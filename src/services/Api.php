<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use justinholtweb\zo\errors\RateLimitException;
use justinholtweb\zo\errors\ZohoApiException;
use justinholtweb\zo\models\LogEntry;
use justinholtweb\zo\Plugin;
use Psr\Http\Message\ResponseInterface;

/**
 * The Zoho Books REST API (v3).
 *
 * Everything Zo sends to Zoho goes through {@see request()}. That is deliberate: the
 * organization scoping, the rate limiter, the one-shot token refresh on a 401 and the log entry
 * are all things that are wrong the moment one caller forgets them, so no caller gets the chance.
 */
class Api extends Component
{
    /**
     * Zoho's own error codes worth reacting to rather than merely reporting.
     */
    public const CODE_RATE_LIMIT = 44;
    public const CODE_RATE_LIMIT_ALT = 45;
    public const CODE_INVALID_TOKEN = 57;

    private const MUTEX_NAME = 'zo:rate-limiter';

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
     * GET, decoded.
     *
     * @param array<string, mixed> $query
     * @param array{action?: string, elementId?: int|null} $context
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], array $context = []): array
    {
        return $this->request('GET', $path, ['query' => $query], $context);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array{action?: string, elementId?: int|null} $context
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, array $query = [], array $context = []): array
    {
        return $this->request('POST', $path, ['query' => $query, 'json' => $body], $context);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array{action?: string, elementId?: int|null} $context
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, array $query = [], array $context = []): array
    {
        return $this->request('PUT', $path, ['query' => $query, 'json' => $body], $context);
    }

    /**
     * The single door to Zoho Books.
     *
     * @param array{query?: array<string, mixed>, json?: array<string, mixed>} $options
     * @param array{action?: string, elementId?: int|null} $context
     * @return array<string, mixed> the decoded response envelope
     * @throws ZohoApiException
     * @throws RateLimitException
     * @throws \justinholtweb\zo\errors\NotConnectedException
     */
    public function request(string $method, string $path, array $options = [], array $context = [], bool $isRetry = false): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $auth = Plugin::getInstance()->getAuth();

        $this->throttle();

        $query = $options['query'] ?? [];
        // Every Books call is scoped to one organization. Omitting it is not an auth error and not
        // a 400 — Zoho answers for whichever organization it feels like, which on a multi-entity
        // account means invoices quietly filed against the wrong company.
        $query['organization_id'] = $settings->getParsedOrganizationId();

        $requestOptions = [
            'query' => $query,
            'timeout' => $settings->requestTimeout,
            'headers' => [
                'Authorization' => 'Zoho-oauthtoken ' . $auth->getAccessToken($isRetry),
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ];

        if (isset($options['json'])) {
            $requestOptions['json'] = $options['json'];
        }

        $url = $settings->getApiBase() . ltrim($path, '/');
        $action = $context['action'] ?? strtolower($method) . ':' . trim($path, '/');
        $started = microtime(true);

        try {
            $response = Craft::createGuzzleClient($this->clientConfig)->request($method, $url, $requestOptions);
        } catch (\Throwable $e) {
            // No response at all: DNS, TLS, a connect timeout. There is no status code to record
            // and nothing to parse, so the message is all the merchant gets — and it is enough,
            // because every one of these means "the network", not "the payload".
            $this->log($action, LogEntry::LEVEL_ERROR, $method, $url, null, null, $started, $e->getMessage(), $options, null, $context);

            throw new ZohoApiException($e->getMessage(), null, null, null, $e);
        }

        return $this->handle($response, $method, $path, $url, $options, $context, $action, $started, $isRetry);
    }

    /**
     * Whether the credentials work, and against which organization.
     *
     * @return array{success: bool, message: string, organizations?: array<int, array{id: string, name: string}>}
     */
    public function testConnection(): array
    {
        $settings = Plugin::getInstance()->getSettings();

        try {
            // `organizations` is the one Books endpoint that is not itself scoped to an
            // organization, which makes it the only call that can tell a wrong organization id
            // apart from a dead token.
            $data = $this->request('GET', 'organizations', [], ['action' => 'test']);
            $organizations = [];

            foreach ($data['organizations'] ?? [] as $organization) {
                $organizations[] = [
                    'id' => (string)($organization['organization_id'] ?? ''),
                    'name' => (string)($organization['name'] ?? ''),
                ];
            }

            $configured = $settings->getParsedOrganizationId();
            $match = null;

            foreach ($organizations as $organization) {
                if ($organization['id'] === $configured) {
                    $match = $organization;
                    break;
                }
            }

            if ($configured === '') {
                return [
                    'success' => false,
                    'message' => Craft::t('zo', 'Connected, but no organization ID is set. Pick one below.'),
                    'organizations' => $organizations,
                ];
            }

            if ($match === null) {
                return [
                    'success' => false,
                    'message' => Craft::t('zo', 'Connected, but organization {id} is not one this account can see.', ['id' => $configured]),
                    'organizations' => $organizations,
                ];
            }

            return [
                'success' => true,
                'message' => Craft::t('zo', 'Connected to {name}.', ['name' => $match['name']]),
                'organizations' => $organizations,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * The organizations this account can see, for the settings screen's picker.
     *
     * @return array<int, array{id: string, name: string, currency: string}>
     */
    public function getOrganizations(): array
    {
        $data = $this->request('GET', 'organizations', [], ['action' => 'organizations']);
        $out = [];

        foreach ($data['organizations'] ?? [] as $organization) {
            $out[] = [
                'id' => (string)($organization['organization_id'] ?? ''),
                'name' => (string)($organization['name'] ?? ''),
                'currency' => (string)($organization['currency_code'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The organization's tax rates, for the tax mapping screen.
     *
     * @return array<int, array{id: string, name: string, percentage: float}>
     */
    public function getTaxes(): array
    {
        $data = $this->get('settings/taxes', [], ['action' => 'taxes']);
        $out = [];

        foreach ($data['taxes'] ?? [] as $tax) {
            $out[] = [
                'id' => (string)($tax['tax_id'] ?? ''),
                'name' => (string)($tax['tax_name'] ?? ''),
                'percentage' => (float)($tax['tax_percentage'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The organization's bank and cash accounts, for the deposit-account setting.
     *
     * @return array<int, array{id: string, name: string, type: string}>
     */
    public function getAccounts(): array
    {
        $data = $this->get('chartofaccounts', ['filter_by' => 'AccountType.Active'], ['action' => 'accounts']);
        $out = [];

        foreach ($data['chartofaccounts'] ?? [] as $account) {
            $type = (string)($account['account_type'] ?? '');

            if (!in_array($type, ['bank', 'cash', 'other_current_asset'], true)) {
                continue;
            }

            $out[] = [
                'id' => (string)($account['account_id'] ?? ''),
                'name' => (string)($account['account_name'] ?? ''),
                'type' => $type,
            ];
        }

        return $out;
    }

    // Private
    // =========================================================================

    /**
     * @param array{query?: array<string, mixed>, json?: array<string, mixed>} $options
     * @param array{action?: string, elementId?: int|null} $context
     * @return array<string, mixed>
     */
    private function handle(
        ResponseInterface $response,
        string $method,
        string $path,
        string $url,
        array $options,
        array $context,
        string $action,
        float $started,
        bool $isRetry,
    ): array {
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        $data = Json::decodeIfJson($body);
        $data = is_array($data) ? $data : [];
        $zohoCode = isset($data['code']) ? (int)$data['code'] : null;
        $message = (string)($data['message'] ?? '');

        // An expired or revoked access token. One retry, with a forced refresh — and exactly one,
        // because a genuinely revoked *refresh* token would otherwise loop until the request
        // timed out.
        if (($status === 401 || $zohoCode === self::CODE_INVALID_TOKEN) && !$isRetry) {
            Plugin::getInstance()->getAuth()->forgetAccessToken();

            return $this->request($method, $path, $options, $context, true);
        }

        if ($status === 429 || $zohoCode === self::CODE_RATE_LIMIT || $zohoCode === self::CODE_RATE_LIMIT_ALT) {
            $isDaily = stripos($message, 'day') !== false;
            $this->log($action, LogEntry::LEVEL_WARNING, $method, $url, $status, $zohoCode, $started, $message, $options, $body, $context);

            throw new RateLimitException(
                $message !== '' ? $message : Craft::t('zo', 'Zoho’s rate limit was reached.'),
                $isDaily ? 3600 : 60,
                $isDaily,
                $status,
                $zohoCode,
                $body
            );
        }

        // Zoho signals success two ways at once — a 2xx and `"code": 0` — and they can disagree.
        // A 200 carrying a non-zero code is a failure however healthy the status line looks.
        if ($status >= 200 && $status < 300 && ($zohoCode === null || $zohoCode === 0)) {
            $this->log($action, LogEntry::LEVEL_INFO, $method, $url, $status, $zohoCode, $started, $message ?: 'OK', $options, $body, $context);

            return $data;
        }

        $this->log($action, LogEntry::LEVEL_ERROR, $method, $url, $status, $zohoCode, $started, $message, $options, $body, $context);

        throw new ZohoApiException(
            $message !== ''
                ? Craft::t('zo', 'Zoho Books: {message}', ['message' => $message])
                : Craft::t('zo', 'Zoho Books returned HTTP {status}.', ['status' => $status]),
            $status,
            $zohoCode,
            $body
        );
    }

    /**
     * Hold the call until this minute has a slot free.
     *
     * Zoho's ceiling is 100 requests a minute per organization and it is enforced by locking the
     * account out, not by queueing — so the cost of overshooting is far higher than the cost of a
     * short wait. The counter is deliberately shared across web requests, queue workers and
     * console commands, since Zoho counts them all the same.
     *
     * @throws RateLimitException when no slot frees up in time
     */
    private function throttle(): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $limit = max(1, $settings->requestsPerMinute);
        $deadline = microtime(true) + max(0, $settings->rateLimitWaitSeconds);

        do {
            if ($this->consumeSlot($limit)) {
                return;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(250000);
        } while (true);

        throw new RateLimitException(
            Craft::t('zo', 'Zo held this call back to stay under Zoho’s {limit}-per-minute limit.', ['limit' => $limit]),
            (int)max(1, 60 - (int)date('s')),
            false,
            null
        );
    }

    private function consumeSlot(int $limit): bool
    {
        $cache = Craft::$app->getCache();
        $key = 'zo:rate:' . Plugin::getInstance()->getSettings()->getParsedOrganizationId() . ':' . (int)floor(time() / 60);
        $mutex = Craft::$app->getMutex();

        // Without the lock two workers both read 89 and both write 90, and the organization goes
        // over the limit while the counter says it did not.
        if (!$mutex->acquire(self::MUTEX_NAME, 5)) {
            // Better to make the call than to stall the sync on a lock we cannot get; Zoho's own
            // 429 handling is the backstop.
            return true;
        }

        try {
            $used = (int)$cache->get($key);

            if ($used >= $limit) {
                return false;
            }

            $cache->set($key, $used + 1, 120);

            return true;
        } finally {
            $mutex->release(self::MUTEX_NAME);
        }
    }

    /**
     * @param array{query?: array<string, mixed>, json?: array<string, mixed>} $options
     * @param array{action?: string, elementId?: int|null} $context
     */
    private function log(
        string $action,
        string $level,
        string $method,
        string $url,
        ?int $status,
        ?int $zohoCode,
        float $started,
        string $summary,
        array $options,
        ?string $response,
        array $context,
    ): void {
        Plugin::getInstance()->getLog()->write($action, [
            'level' => $level,
            'method' => $method,
            'endpoint' => $url,
            'statusCode' => $status,
            'zohoCode' => $zohoCode,
            'durationMs' => (int)round((microtime(true) - $started) * 1000),
            'elementId' => $context['elementId'] ?? null,
            'summary' => $summary,
            'request' => isset($options['json']) ? Json::encode($options['json']) : null,
            'response' => $response,
        ]);
    }
}
