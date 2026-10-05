<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use justinholtweb\zo\errors\NotConnectedException;
use justinholtweb\zo\errors\ZohoApiException;
use justinholtweb\zo\models\LogEntry;
use justinholtweb\zo\Plugin;

/**
 * The OAuth 2.0 token lifecycle.
 *
 * Zoho's access tokens last an hour and its refresh tokens do not expire, so the steady state is:
 * one refresh token in the settings, one access token in the cache, and a refresh call roughly
 * once an hour. Everything here exists to keep that true under concurrency — a backfill running
 * twenty queue jobs must not perform twenty refreshes.
 */
class Auth extends Component
{
    /**
     * Renew this many seconds before Zoho says the token expires, so a token that is valid when a
     * request is built is still valid when it arrives.
     */
    public const EXPIRY_MARGIN = 120;

    /**
     * How long a caller waits for whichever process is already refreshing.
     */
    public const MUTEX_TIMEOUT = 15;

    private const MUTEX_NAME = 'zo:token-refresh';

    /**
     * Extra Guzzle options merged into every request this service makes.
     *
     * Real uses: an outbound proxy, a corporate CA bundle, a lower timeout for a health check.
     * The test suite uses it to install a mock transport, which is how Zoho's more awkward
     * answers — a 401 that clears on refresh, a 429, a 200 carrying a failure code — get
     * exercised without an account and without waiting on a rate limiter.
     *
     * @var array<string, mixed>
     */
    public array $clientConfig = [];

    /**
     * A valid access token, refreshing it if necessary.
     *
     * @throws NotConnectedException if Zo has never been connected
     * @throws ZohoApiException if Zoho refuses to mint a token
     */
    public function getAccessToken(bool $force = false): string
    {
        $settings = Plugin::getInstance()->getSettings();

        // Credentials, not "connected": a token needs no organization, and the calls that find one
        // — the pick after connecting, "Look up organizations" — run before there is one. Before
        // 5.0.1 this asked for the organization too, so neither could ever succeed.
        if (!$settings->getHasCredentials()) {
            throw new NotConnectedException(Craft::t('zo', 'Zo is not connected to Zoho Books.'));
        }

        $cache = Craft::$app->getCache();
        $key = $this->cacheKey();

        if (!$force) {
            $cached = $cache->get($key);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $mutex = Craft::$app->getMutex();
        $locked = $mutex->acquire(self::MUTEX_NAME, self::MUTEX_TIMEOUT);

        try {
            // Whoever held the lock has almost certainly just refreshed. Re-reading the cache
            // here is the difference between one refresh per hour and one per concurrent job.
            if (!$force) {
                $cached = $cache->get($key);

                if (is_string($cached) && $cached !== '') {
                    return $cached;
                }
            }

            $response = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $settings->getParsedRefreshToken(),
                'client_id' => $settings->getParsedClientId(),
                'client_secret' => $settings->getParsedClientSecret(),
            ]);

            $token = (string)($response['access_token'] ?? '');

            if ($token === '') {
                throw new ZohoApiException(Craft::t('zo', 'Zoho returned no access token.'));
            }

            $expiresIn = (int)($response['expires_in'] ?? 3600);
            $ttl = max(60, $expiresIn - self::EXPIRY_MARGIN);

            $cache->set($key, $token, $ttl);

            return $token;
        } finally {
            if ($locked) {
                $mutex->release(self::MUTEX_NAME);
            }
        }
    }

    /**
     * Throw away the cached access token, so the next call refreshes.
     */
    public function forgetAccessToken(): void
    {
        Craft::$app->getCache()->delete($this->cacheKey());
    }

    /**
     * Where the merchant is sent to grant access.
     *
     * `access_type=offline` is what produces a refresh token at all, and `prompt=consent` is what
     * makes Zoho hand one over on a *repeat* authorization — without it a merchant reconnecting an
     * existing app gets an access token and no refresh token, and the connection dies an hour
     * later with no explanation.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $settings = Plugin::getInstance()->getSettings();

        $params = [
            'scope' => implode(',', $settings->getScopes()),
            'client_id' => $settings->getParsedClientId(),
            'response_type' => 'code',
            'redirect_uri' => $settings->getRedirectUri(),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return $settings->getAccountsUrl() . '/oauth/v2/auth?' . http_build_query($params);
    }

    /**
     * Trade the authorization code for a refresh token.
     *
     * `$accountsServer` is the `accounts-server` parameter Zoho adds to the redirect. It names the
     * data centre the merchant actually authorised against, which is not necessarily the one
     * configured here — a EU merchant who left the setting on `.com` gets an `invalid_code` from
     * the US accounts server, and honouring the redirect's own answer turns that into a working
     * connection plus a corrected setting.
     *
     * @return array{refreshToken: string, accessToken: string, apiDomain: ?string, dataCenter: ?string}
     * @throws ZohoApiException
     */
    public function exchangeCode(string $code, ?string $accountsServer = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $dataCenter = $accountsServer !== null ? self::dataCenterFromUrl($accountsServer) : null;
        $accountsUrl = $dataCenter !== null
            ? 'https://accounts.zoho.' . $dataCenter
            : $settings->getAccountsUrl();

        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $settings->getParsedClientId(),
            'client_secret' => $settings->getParsedClientSecret(),
            'redirect_uri' => $settings->getRedirectUri(),
        ], $accountsUrl);

        $refreshToken = (string)($response['refresh_token'] ?? '');

        if ($refreshToken === '') {
            throw new ZohoApiException(Craft::t(
                'zo',
                'Zoho returned no refresh token. Re-authorise with “prompt=consent”, or remove Zo from the Zoho account’s connected apps and try again.'
            ));
        }

        return [
            'refreshToken' => $refreshToken,
            'accessToken' => (string)($response['access_token'] ?? ''),
            'apiDomain' => isset($response['api_domain']) ? (string)$response['api_domain'] : null,
            'dataCenter' => $dataCenter,
        ];
    }

    /**
     * Tell Zoho to forget the refresh token, so disconnecting in Craft actually disconnects.
     *
     * Best-effort by design: if the token is already dead, or the accounts server is unreachable,
     * the local disconnect still has to happen or the merchant is stuck with a connection they
     * cannot remove.
     */
    public function revokeRefreshToken(?string $token = null): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $token ??= $settings->getParsedRefreshToken();

        if ($token === '') {
            return false;
        }

        try {
            Craft::createGuzzleClient($this->clientConfig + ['timeout' => 15])->post(
                $settings->getAccountsUrl() . '/oauth/v2/token/revoke',
                ['form_params' => ['token' => $token]]
            );

            return true;
        } catch (\Throwable $e) {
            Craft::warning('Zo could not revoke its refresh token: ' . $e->getMessage(), __METHOD__);

            return false;
        }
    }

    /**
     * Forget this environment's connection, at both ends.
     *
     * Revokes every token Zo knows about — the stored connection's and, on an install from before
     * 5.0.1, the literal one still in project config — because forgetting only the first would
     * quietly hand the job to the second. An `$ENV` token is the site's own to revoke.
     */
    public function disconnect(): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $tokens = array_unique(array_filter([
            $plugin->getConnection()->get()['refreshToken'] ?? null,
            $settings->storesLiteralRefreshToken() ? trim($settings->refreshToken) : null,
        ]));

        foreach ($tokens as $token) {
            $this->revokeRefreshToken($token);
        }

        $this->forgetAccessToken();
        $plugin->getConnection()->forget();
    }

    /**
     * A one-time value tying the redirect back to the session that started it.
     */
    public function generateState(): string
    {
        $state = StringHelper::UUID();
        Craft::$app->getSession()->set('zo.oauthState', $state);

        return $state;
    }

    /**
     * Consume the stored state. Single use: a replayed redirect must not authorise anything.
     */
    public function consumeState(?string $state): bool
    {
        $session = Craft::$app->getSession();
        $expected = $session->get('zo.oauthState');
        $session->remove('zo.oauthState');

        return is_string($expected) && $expected !== '' && hash_equals($expected, (string)$state);
    }

    /**
     * Map `https://accounts.zoho.eu` to `eu`.
     */
    public static function dataCenterFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        if (!preg_match('/accounts\.zoho\.(.+)$/i', $host, $matches)) {
            return null;
        }

        $suffix = strtolower(trim($matches[1], '.'));

        return isset(\justinholtweb\zo\models\Settings::DATA_CENTERS[$suffix]) ? $suffix : null;
    }

    // Private
    // =========================================================================

    /**
     * POST to the accounts server and hand back the decoded body.
     *
     * The trap: Zoho's accounts server answers a bad grant with **HTTP 200** and
     * `{"error":"invalid_code"}`. Guzzle sees a success, `decode` sees an array, and an integration
     * that only checks the status code goes on to cache an empty access token and fail every
     * subsequent call with a 401 that points nowhere near the real problem.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     * @throws ZohoApiException
     */
    private function tokenRequest(array $params, ?string $accountsUrl = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $url = ($accountsUrl ?? $settings->getAccountsUrl()) . '/oauth/v2/token';
        $started = microtime(true);

        try {
            $response = Craft::createGuzzleClient($this->clientConfig + ['timeout' => $settings->requestTimeout])
                ->post($url, ['form_params' => $params]);

            $body = (string)$response->getBody();
            $data = Json::decodeIfJson($body);

            if (!is_array($data)) {
                throw new ZohoApiException(
                    Craft::t('zo', 'Zoho’s accounts server returned something that is not JSON.'),
                    $response->getStatusCode(),
                    null,
                    $body
                );
            }

            if (isset($data['error'])) {
                throw new ZohoApiException(
                    $this->describeOauthError((string)$data['error']),
                    $response->getStatusCode(),
                    null,
                    $body
                );
            }

            $this->log('oauth', LogEntry::LEVEL_INFO, $started, $url, $response->getStatusCode(), Craft::t('zo', 'Token issued ({grant})', [
                'grant' => $params['grant_type'] ?? '?',
            ]), $params, $body);

            return $data;
        } catch (ZohoApiException $e) {
            $this->log('oauth', LogEntry::LEVEL_ERROR, $started, $url, $e->statusCode, $e->getMessage(), $params, $e->body);

            throw $e;
        } catch (\Throwable $e) {
            $this->log('oauth', LogEntry::LEVEL_ERROR, $started, $url, null, $e->getMessage(), $params, null);

            throw new ZohoApiException($e->getMessage(), null, null, null, $e);
        }
    }

    /**
     * Zoho's OAuth errors are single words. Each one has exactly one cause and exactly one fix,
     * and neither is guessable from the word itself.
     */
    private function describeOauthError(string $error): string
    {
        return match ($error) {
            'invalid_code' => Craft::t('zo', 'Zoho rejected the authorization code. Codes are single-use and expire after two minutes — start the connection again.'),
            'invalid_client' => Craft::t('zo', 'Zoho rejected the client ID or secret. Check they came from the same Zoho API console entry, in the same data centre.'),
            'invalid_client_secret' => Craft::t('zo', 'Zoho rejected the client secret.'),
            'invalid_redirect_uri' => Craft::t('zo', 'The redirect URI does not match the one registered in the Zoho API console. It must match exactly, including the scheme and any trailing path.'),
            'access_denied' => Craft::t('zo', 'Access was denied in Zoho.'),
            'invalid_grant' => Craft::t('zo', 'Zoho rejected the refresh token. It has probably been revoked — reconnect Zo.'),
            default => Craft::t('zo', 'Zoho returned “{error}”.', ['error' => $error]),
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function log(string $action, string $level, float $started, string $url, ?int $status, string $summary, array $params, ?string $response): void
    {
        Plugin::getInstance()->getLog()->write($action, [
            'level' => $level,
            'method' => 'POST',
            'endpoint' => $url,
            'statusCode' => $status,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
            'summary' => $summary,
            'request' => Json::encode(array_merge($params, [
                'client_secret' => '…redacted…',
                'refresh_token' => '…redacted…',
                'code' => '…redacted…',
            ])),
            'response' => $response,
        ]);
    }

    private function cacheKey(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        // Keyed on the credentials, not just on "zo": swapping the organization or the client id
        // has to invalidate the token, or the new configuration keeps using the old account's.
        return 'zo:accessToken:' . md5(implode('|', [
            $settings->getParsedClientId(),
            $settings->getParsedOrganizationId(),
            $settings->getSafeDataCenter(),
            substr(sha1($settings->getParsedRefreshToken()), 0, 16),
        ]));
    }
}
