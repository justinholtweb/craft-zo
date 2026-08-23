<?php
/**
 * Zo integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-zo/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, orders, link rows, log rows and the plugin
 * settings it overwrites are all restored in a `finally`, pass or fail.
 *
 * Zoho itself is emulated through a Guzzle mock transport installed on the seam the Api and Auth
 * services expose for proxies. That is not a shortcut around testing the wire: it is the only way
 * to exercise the answers that matter — a 401 that clears on refresh, a 429, a 200 carrying a
 * failure code, a body that is not JSON — deterministically and without an account. One real
 * round-trip against the local web server covers the case the mock cannot: a live endpoint
 * answering with HTML.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\errors\NotConnectedException;
use justinholtweb\zo\errors\RateLimitException;
use justinholtweb\zo\errors\ZohoApiException;
use justinholtweb\zo\helpers\Money;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\models\LogEntry;
use justinholtweb\zo\models\Settings;
use justinholtweb\zo\models\SyncResult;
use justinholtweb\zo\Plugin;
use justinholtweb\zo\services\Log as LogService;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$storeId = $commerce->getStores()->getPrimaryStore()->id;
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$originalSettings = $plugin->getSettings()->toArray();

// A run killed mid-way (a container restart, a Ctrl-C) never reaches its `finally`, so its
// fixture credentials stay in project config — and the next run would then snapshot *those* as
// the originals and restore them forever. Recognising them and dropping them makes the suite
// self-healing rather than quietly corrupting the install it runs on.
if (($originalSettings['clientId'] ?? null) === 'fixture-client') {
    echo "  ! clearing fixture credentials left behind by an interrupted run\n";

    foreach (['clientId', 'clientSecret', 'refreshToken', 'organizationId'] as $credential) {
        $originalSettings[$credential] = '';
    }

    $originalSettings['customFields'] = [];
    $originalSettings['taxMap'] = [];
    $originalSettings['paymentModeMap'] = [];
    $originalSettings['adjustmentDescription'] = 'Sales tax';
}

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent`, but Craft passes an
// `ElementEvent` for that event — so saving *any* element fatals while it is enabled. Nothing to
// do with Zo; detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

/**
 * Persist settings for the duration of the run. Project config writes are buffered until the
 * request ends, and a bare console script has no request end — so it has to flush them itself.
 */
function applySettings(array $values): void
{
    global $plugin;

    refreshConfigVersion();
    Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    refreshConfigVersion();
}

/**
 * Re-read the config version this process thinks it is holding.
 *
 * Craft refuses a project config write when the `configVersion` in the database no longer matches
 * the one cached on `Craft::$app->getInfo()`, on the reasonable assumption that another request
 * moved underneath it. In a console script the "other request" is this one, three writes ago —
 * so a long-running script has to re-read the version or its second write fails as stale.
 */
function refreshConfigVersion(): void
{
    $stored = (new craft\db\Query())
        ->select(['configVersion'])
        ->from([craft\db\Table::INFO])
        ->scalar();

    if ($stored) {
        Craft::$app->getInfo()->configVersion = $stored;
    }
}

/**
 * Set settings on the in-memory model only.
 *
 * Most checks want a different tax mode or number source for one assertion, and a project-config
 * round trip per assertion would make the suite take minutes rather than seconds.
 */
function setSettings(array $values): void
{
    global $plugin;

    $settings = $plugin->getSettings();

    foreach ($values as $key => $value) {
        $settings->$key = $value;
    }
}

// The mock transport. Every response Zo will be given, in order, plus a journal of what it sent.
$mockQueue = null;
$journal = [];

/**
 * Install a queue of canned responses and start a fresh journal.
 *
 * @param array<int, GuzzleResponse|Throwable> $responses
 */
function mockZoho(array $responses): void
{
    global $mockQueue, $journal, $plugin;

    $journal = [];
    $mockQueue = new MockHandler($responses);
    $stack = HandlerStack::create($mockQueue);
    $stack->push(Middleware::history($journal));

    $plugin->getApi()->clientConfig = ['handler' => $stack];
    $plugin->getAuth()->clientConfig = ['handler' => $stack];

    resetRateLimiter();
}

/**
 * Give the rate limiter a clean minute.
 *
 * The counter is shared across processes on purpose — Zoho counts every caller against one
 * budget — which means two runs of this suite inside the same minute would have the second one
 * throttled by the first. Every canned scenario starts from zero instead.
 */
function resetRateLimiter(): void
{
    $orgId = Plugin::getInstance()->getSettings()->getParsedOrganizationId();
    $minute = (int)floor(time() / 60);

    foreach ([$minute - 1, $minute] as $slot) {
        Craft::$app->getCache()->delete('zo:rate:' . $orgId . ':' . $slot);
    }
}

function clearMock(): void
{
    global $plugin;

    $plugin->getApi()->clientConfig = [];
    $plugin->getAuth()->clientConfig = [];
}

/**
 * A Zoho success envelope.
 */
function zohoOk(array $payload, int $status = 201): GuzzleResponse
{
    return new GuzzleResponse($status, ['Content-Type' => 'application/json'], json_encode(
        array_merge(['code' => 0, 'message' => 'success'], $payload)
    ));
}

function zohoError(int $status, int $code, string $message): GuzzleResponse
{
    return new GuzzleResponse($status, ['Content-Type' => 'application/json'], json_encode([
        'code' => $code,
        'message' => $message,
    ]));
}

function tokenResponse(string $accessToken = 'access-token-1', int $expiresIn = 3600): GuzzleResponse
{
    return new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => $accessToken,
        'expires_in' => $expiresIn,
        'api_domain' => 'https://www.zohoapis.com',
        'token_type' => 'Bearer',
    ]));
}

/**
 * The requests Zo actually made, as `METHOD path?query` strings.
 *
 * @return string[]
 */
function sentRequests(): array
{
    global $journal;

    return array_map(static function(array $entry) {
        $uri = $entry['request']->getUri();

        return $entry['request']->getMethod() . ' ' . $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');
    }, $journal);
}

/**
 * The index of the first request whose path contains $needle, or null.
 */
function requestIndex(string $needle): ?int
{
    foreach (sentRequests() as $index => $request) {
        if (str_contains($request, $needle)) {
            return $index;
        }
    }

    return null;
}

/**
 * The decoded JSON body of the nth request Zo made.
 */
function sentBody(int $index): array
{
    global $journal;

    if (!isset($journal[$index])) {
        return [];
    }

    $decoded = json_decode((string)$journal[$index]['request']->getBody(), true);

    return is_array($decoded) ? $decoded : [];
}

function connectFixture(): void
{
    setSettings([
        'clientId' => 'fixture-client',
        'clientSecret' => 'fixture-secret',
        'refreshToken' => 'fixture-refresh',
        'organizationId' => '10234695',
        'dataCenter' => 'com',
    ]);

    Plugin::getInstance()->getAuth()->forgetAccessToken();
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Zo fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product, true, true, false)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

/**
 * @param array<int, array{variant: Variant, qty: int}> $lines
 */
function makeOrder(array $lines, bool $complete = true, ?string $email = null): Order
{
    global $createdOrders, $storeId;

    $order = new Order();
    $order->storeId = $storeId;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = Commerce::getInstance()->getCarts()->generateCartNumber();
    // A unique address per order unless one is asked for: Zo reuses a Zoho contact across every
    // order from the same customer, so a shared email means later syncs make no contact call at
    // all — correct behaviour, and it would quietly consume the wrong queued response here.
    $order->setEmail($email ?? 'zo-fixture-' . bin2hex(random_bytes(5)) . '@example.com');

    // Commerce's `createTransaction()` reads `$order->getGateway()->id` unconditionally, so an
    // order with no gateway fatals rather than failing validation.
    $gateway = Commerce::getInstance()->getGateways()->getAllGateways()->firstWhere('handle', 'dummy');

    if ($gateway !== null) {
        $order->gatewayId = $gateway->id;
    }

    if (!Craft::$app->getElements()->saveElement($order, false, true, false)) {
        throw new RuntimeException('Could not save order: ' . json_encode($order->getErrors()));
    }

    $createdOrders[] = $order;

    $lineItems = [];

    foreach ($lines as $line) {
        $lineItems[] = Commerce::getInstance()->getLineItems()->createLineItem(
            $order,
            $line['variant']->id,
            [],
            $line['qty']
        );
    }

    $order->setLineItems($lineItems);

    // Commerce refuses an address element it does not own, so the attributes go in as an array and
    // Commerce builds the owned element itself.
    $address = [
        'fullName' => 'Dana Fixture',
        'organization' => 'Fixture Supplies Ltd',
        'addressLine1' => '742 Evergreen Terrace',
        'addressLine2' => 'Unit 3',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'countryCode' => 'US',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);

    if (!Craft::$app->getElements()->saveElement($order, false, true, false)) {
        throw new RuntimeException('Could not save order lines: ' . json_encode($order->getErrors()));
    }

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

function addTransaction(Order $order, string $type, float $amount, ?int $parentId = null): craft\commerce\models\Transaction
{
    $transactions = Commerce::getInstance()->getTransactions();
    $transaction = $transactions->createTransaction($order);
    $transaction->type = $type;
    $transaction->status = TransactionRecord::STATUS_SUCCESS;
    $transaction->amount = $amount;
    $transaction->paymentAmount = $amount;
    $transaction->reference = 'ref-' . bin2hex(random_bytes(4));
    $transaction->parentId = $parentId;

    $transactions->saveTransaction($transaction);

    return $transaction;
}

function clearLinks(): void
{
    Craft::$app->getDb()->createCommand()->delete(Table::LINKS)->execute();
}

try {
    setSettings($originalSettings);

    // Completing a fixture order fires Zo's own order-complete handler, which queues a real sync
    // job. Dozens of them, over a run — pointing at orders this suite is about to delete.
    setSettings([
        'autoSyncOnComplete' => false,
        'autoSyncOnPaid' => false,
        'syncOnStatusHandles' => [],
        'syncViaQueue' => true,
    ]);

    connectFixture();

    // =====================================================================
    section('Money');

    check('rounds half up, the way an invoice does', function() {
        return Money::round(2.675) === 2.68 ?: 'got ' . Money::round(2.675);
    });

    check('treats a sub-cent difference as the same money', function() {
        return Money::equal(0.1 + 0.2, 0.3) === true;
    });

    check('does not treat a cent as noise', function() {
        return Money::equal(10.00, 10.01) === false;
    });

    // =====================================================================
    section('Settings');

    check('derives the API base from the data centre', function() use ($plugin) {
        setSettings(['dataCenter' => 'eu', 'apiBaseUrl' => '']);

        return $plugin->getSettings()->getApiBase() === 'https://www.zohoapis.eu/books/v3/'
            ?: $plugin->getSettings()->getApiBase();
    });

    check('derives the accounts URL from the data centre', function() use ($plugin) {
        return $plugin->getSettings()->getAccountsUrl() === 'https://accounts.zoho.eu'
            ?: $plugin->getSettings()->getAccountsUrl();
    });

    check('falls back to .com for an unknown data centre', function() use ($plugin) {
        setSettings(['dataCenter' => 'nonsense']);
        $base = $plugin->getSettings()->getApiBase();
        setSettings(['dataCenter' => 'com']);

        return $base === 'https://www.zohoapis.com/books/v3/' ?: $base;
    });

    check('an API base override always ends in a slash', function() use ($plugin) {
        setSettings(['apiBaseUrl' => 'http://proxy.internal/books/v3']);
        $base = $plugin->getSettings()->getApiBase();
        setSettings(['apiBaseUrl' => '']);

        return $base === 'http://proxy.internal/books/v3/' ?: $base;
    });

    check('an accounts URL override never ends in a slash', function() use ($plugin) {
        setSettings(['accountsUrl' => 'http://proxy.internal/accounts/']);
        $url = $plugin->getSettings()->getAccountsUrl();
        setSettings(['accountsUrl' => '']);

        return $url === 'http://proxy.internal/accounts' ?: $url;
    });

    check('the redirect URI is a bare control panel path', function() use ($plugin) {
        $uri = $plugin->getSettings()->getRedirectUri();

        return str_contains($uri, '/zo/oauth/callback')
            && !str_contains($uri, 'actions/')
            && !str_contains($uri, 'site=')
            ?: $uri;
    });

    check('the redirect URI carries no query string on this install', function() use ($plugin) {
        return $plugin->getSettings()->getRedirectUriIsClean() === true
            ?: $plugin->getSettings()->getRedirectUri();
    });

    check('is not connected when any credential is missing', function() use ($plugin) {
        setSettings(['organizationId' => '']);
        $connected = $plugin->getSettings()->getIsConnected();
        connectFixture();

        return $connected === false;
    });

    check('is connected when all four are present', function() use ($plugin) {
        return $plugin->getSettings()->getIsConnected() === true;
    });

    check('scopes narrow to what is switched on', function() use ($plugin) {
        setSettings(['syncItems' => false, 'syncPayments' => false, 'syncRefunds' => false, 'documentType' => 'invoice']);
        $narrow = $plugin->getSettings()->getScopes();

        setSettings(['syncItems' => true, 'syncPayments' => true, 'syncRefunds' => true, 'documentType' => 'both']);
        $wide = $plugin->getSettings()->getScopes();

        // Put it back: a leaked `both` would have every later check syncing a sales order too.
        setSettings(['documentType' => 'invoice']);

        return !in_array('ZohoBooks.items.CREATE', $narrow, true)
            && in_array('ZohoBooks.items.CREATE', $wide, true)
            && in_array('ZohoBooks.creditnotes.CREATE', $wide, true)
            && in_array('ZohoBooks.salesorders.CREATE', $wide, true);
    });

    check('never asks for fullaccess', function() use ($plugin) {
        foreach ($plugin->getSettings()->getScopes() as $scope) {
            if (str_contains($scope, 'fullaccess')) {
                return "asked for $scope";
            }
        }

        return true;
    });

    check('an editable table row list normalises to a map', function() use ($plugin) {
        $settings = $plugin->getSettings();
        $settings->setCustomFields([
            ['field' => 'cf_po', 'template' => '{{ object.couponCode }}'],
            ['field' => '', 'template' => 'orphan'],
            ['field' => 'cf_empty', 'template' => '  '],
        ]);

        return $settings->getCustomFields() === ['cf_po' => '{{ object.couponCode }}']
            ?: json_encode($settings->getCustomFields());
    });

    check('a map round-trips back out as rows', function() use ($plugin) {
        return $plugin->getSettings()->getCustomFieldRows() === [
            ['field' => 'cf_po', 'template' => '{{ object.couponCode }}'],
        ];
    });

    check('a map read back from project config is accepted unchanged', function() use ($plugin) {
        $settings = $plugin->getSettings();
        $settings->setTaxMap(['standard' => '99001']);

        return $settings->getTaxMap() === ['standard' => '99001'];
    });

    check('the three maps are declared as attributes, or they would never persist', function() use ($plugin) {
        $attributes = $plugin->getSettings()->attributes();

        foreach (['customFields', 'taxMap', 'paymentModeMap'] as $name) {
            if (!in_array($name, $attributes, true)) {
                return "$name is not an attribute";
            }
        }

        return true;
    });

    check('every setting survives a real project config round trip', function() use ($plugin) {
        $settings = $plugin->getSettings();
        $settings->setPaymentModeMap([['gateway' => 'dummy', 'mode' => 'creditcard']]);
        $settings->adjustmentDescription = 'Round trip';
        applySettings($settings->toArray());

        $reloaded = Plugin::getInstance()->getSettings();

        return $reloaded->adjustmentDescription === 'Round trip'
            && $reloaded->getPaymentModeMap() === ['dummy' => 'creditcard']
            ?: json_encode([$reloaded->adjustmentDescription, $reloaded->getPaymentModeMap()]);
    });

    // =====================================================================
    section('Auth');

    check('reads the data centre out of an accounts-server URL', function() {
        return justinholtweb\zo\services\Auth::dataCenterFromUrl('https://accounts.zoho.eu') === 'eu'
            && justinholtweb\zo\services\Auth::dataCenterFromUrl('https://accounts.zoho.com.au') === 'com.au'
            && justinholtweb\zo\services\Auth::dataCenterFromUrl('https://example.com') === null;
    });

    check('the authorization URL carries offline access and a consent prompt', function() use ($plugin) {
        connectFixture();
        $url = $plugin->getAuth()->getAuthorizationUrl('state-123');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        return ($query['access_type'] ?? null) === 'offline'
            && ($query['prompt'] ?? null) === 'consent'
            && ($query['response_type'] ?? null) === 'code'
            && ($query['state'] ?? null) === 'state-123'
            ?: $url;
    });

    check('an access token is fetched and then cached', function() use ($plugin) {
        connectFixture();
        mockZoho([tokenResponse('cached-token')]);

        $first = $plugin->getAuth()->getAccessToken();
        $second = $plugin->getAuth()->getAccessToken();

        // One token response was queued; a second network call would have thrown.
        return $first === 'cached-token' && $second === 'cached-token' && count(sentRequests()) === 1
            ?: 'requests: ' . json_encode(sentRequests());
    });

    check('a forced refresh goes back to Zoho', function() use ($plugin) {
        mockZoho([tokenResponse('fresh-token')]);

        return $plugin->getAuth()->getAccessToken(true) === 'fresh-token';
    });

    check('a 200 carrying an OAuth error is still a failure', function() use ($plugin) {
        mockZoho([new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))]);

        try {
            $plugin->getAuth()->getAccessToken(true);
        } catch (ZohoApiException $e) {
            return str_contains($e->getMessage(), 'revoked') ?: $e->getMessage();
        }

        return 'no exception was thrown';
    });

    check('an unknown OAuth error is reported verbatim rather than swallowed', function() use ($plugin) {
        mockZoho([new GuzzleResponse(200, [], json_encode(['error' => 'something_new']))]);

        try {
            $plugin->getAuth()->getAccessToken(true);
        } catch (ZohoApiException $e) {
            return str_contains($e->getMessage(), 'something_new') ?: $e->getMessage();
        }

        return 'no exception was thrown';
    });

    check('an unconnected install raises NotConnectedException, not an API error', function() use ($plugin) {
        setSettings(['refreshToken' => '']);
        $plugin->getAuth()->forgetAccessToken();

        try {
            $plugin->getAuth()->getAccessToken();
        } catch (NotConnectedException) {
            connectFixture();

            return true;
        } catch (Throwable $e) {
            connectFixture();

            return 'got ' . get_class($e);
        }

        connectFixture();

        return 'no exception was thrown';
    });

    check('the token cache key changes with the organization', function() use ($plugin) {
        connectFixture();
        mockZoho([tokenResponse('org-a-token')]);
        $plugin->getAuth()->getAccessToken();

        setSettings(['organizationId' => '99999999']);
        mockZoho([tokenResponse('org-b-token')]);
        $second = $plugin->getAuth()->getAccessToken();

        connectFixture();

        return $second === 'org-b-token' ?: "got $second";
    });

    // =====================================================================
    section('API transport');

    check('every call is scoped to the organization', function() use ($plugin) {
        connectFixture();
        mockZoho([tokenResponse(), zohoOk(['contacts' => []], 200)]);

        $plugin->getApi()->get('contacts');
        $requests = sentRequests();

        return str_contains($requests[1] ?? '', 'organization_id=10234695') ?: json_encode($requests);
    });

    check('a 200 carrying a non-zero code is a failure', function() use ($plugin) {
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([tokenResponse(), new GuzzleResponse(200, [], json_encode(['code' => 1001, 'message' => 'Contact name already exists']))]);

        try {
            $plugin->getApi()->post('contacts', ['contact_name' => 'x']);
        } catch (ZohoApiException $e) {
            return $e->zohoCode === 1001 ?: 'code was ' . var_export($e->zohoCode, true);
        }

        return 'no exception was thrown';
    });

    check('a 401 refreshes the token and retries exactly once', function() use ($plugin) {
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse('stale'),
            new GuzzleResponse(401, [], json_encode(['code' => 57, 'message' => 'Invalid oauth token'])),
            tokenResponse('renewed'),
            zohoOk(['contact' => ['contact_id' => '42']]),
        ]);

        $response = $plugin->getApi()->post('contacts', ['contact_name' => 'x']);

        return ($response['contact']['contact_id'] ?? null) === '42' && count(sentRequests()) === 4
            ?: json_encode(sentRequests());
    });

    check('a permanently invalid token gives up instead of looping', function() use ($plugin) {
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse('stale'),
            new GuzzleResponse(401, [], json_encode(['code' => 57, 'message' => 'Invalid oauth token'])),
            tokenResponse('also-stale'),
            new GuzzleResponse(401, [], json_encode(['code' => 57, 'message' => 'Invalid oauth token'])),
        ]);

        try {
            $plugin->getApi()->get('contacts');
        } catch (ZohoApiException $e) {
            return $e->statusCode === 401 ?: 'status was ' . var_export($e->statusCode, true);
        }

        return 'no exception was thrown';
    });

    check('a 429 becomes a RateLimitException, not a generic failure', function() use ($plugin) {
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([tokenResponse(), zohoError(429, 44, 'You have exceeded the rate limit')]);

        try {
            $plugin->getApi()->get('contacts');
        } catch (RateLimitException $e) {
            return $e->isDaily === false && $e->isRetryable() === true;
        }

        return 'no rate limit exception';
    });

    check('a daily limit asks for a much longer wait than a per-minute one', function() use ($plugin) {
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([tokenResponse(), zohoError(429, 45, 'You have crossed the API calls limit for the day')]);

        try {
            $plugin->getApi()->get('contacts');
        } catch (RateLimitException $e) {
            return $e->isDaily === true && $e->retryAfter >= 3600 ?: "daily={$e->isDaily} after={$e->retryAfter}";
        }

        return 'no rate limit exception';
    });

    check('Zo throttles itself before Zoho has to', function() use ($plugin) {
        connectFixture();
        $plugin->getAuth()->forgetAccessToken();
        setSettings(['requestsPerMinute' => 1, 'rateLimitWaitSeconds' => 0]);

        // The token call consumes the single slot, so the books call must be held back.
        mockZoho([tokenResponse(), zohoOk(['contacts' => []], 200), zohoOk(['contacts' => []], 200)]);

        try {
            $plugin->getApi()->get('contacts');
            $plugin->getApi()->get('contacts');
        } catch (RateLimitException $e) {
            setSettings(['requestsPerMinute' => 90, 'rateLimitWaitSeconds' => 5]);
            resetRateLimiter();

            return $e->statusCode === null ?: 'the limiter reported a status code';
        }

        setSettings(['requestsPerMinute' => 90, 'rateLimitWaitSeconds' => 5]);
        resetRateLimiter();

        return 'the limiter let both calls through';
    });

    check('a 5xx is retryable and a 400 is not', function() {
        $server = new ZohoApiException('boom', 503);
        $client = new ZohoApiException('bad request', 400);
        $offline = new ZohoApiException('connection reset', null);

        return $server->isRetryable() === true
            && $client->isRetryable() === false
            && $offline->isRetryable() === true;
    });

    check('an endpoint answering with HTML fails cleanly rather than fatally', function() use ($plugin) {
        // A real round trip: the local web server answers a nonsense path with Craft's 404 page.
        clearMock();
        connectFixture();
        resetRateLimiter();
        Craft::$app->getCache()->set('zo:accessToken:' . md5(implode('|', [
            'fixture-client', '10234695', 'com', substr(sha1('fixture-refresh'), 0, 16),
        ])), 'pretend-token', 300);
        setSettings(['apiBaseUrl' => 'http://localhost/zo-not-a-real-endpoint/', 'requestTimeout' => 10]);

        try {
            $plugin->getApi()->get('contacts');
        } catch (ZohoApiException $e) {
            setSettings(['apiBaseUrl' => '']);

            return $e->statusCode !== null ?: 'no status code; message was: ' . $e->getMessage();
        } finally {
            setSettings(['apiBaseUrl' => '']);
            $plugin->getAuth()->forgetAccessToken();
        }

        return 'the HTML response was accepted as success';
    });

    // =====================================================================
    section('The id map');

    clearLinks();

    check('claiming twice returns the same row', function() use ($plugin) {
        $first = $plugin->getLinks()->claim(Link::TYPE_INVOICE, 'order:1');
        $second = $plugin->getLinks()->claim(Link::TYPE_INVOICE, 'order:1');

        return $first->id === $second->id ?: "{$first->id} vs {$second->id}";
    });

    check('the unique index really is enforced', function() {
        try {
            Craft::$app->getDb()->createCommand()->insert(Table::LINKS, [
                'type' => Link::TYPE_INVOICE,
                'craftKey' => 'order:1',
                'status' => Link::STATUS_PENDING,
                'attempts' => 0,
                'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'dateUpdated' => craft\helpers\Db::prepareDateForDb(new DateTime()),
                'uid' => craft\helpers\StringHelper::UUID(),
            ])->execute();
        } catch (Throwable) {
            return true;
        }

        return 'a duplicate (type, craftKey) was accepted';
    });

    check('marking synced records both totals and the variance between them', function() use ($plugin) {
        $link = $plugin->getLinks()->claim(Link::TYPE_INVOICE, 'order:2');
        $plugin->getLinks()->markSynced($link, 'zoho-1', 'INV-000001', 100.00, 100.05);

        return $link->variance !== null && abs($link->variance - 0.05) < 0.0001 ?: var_export($link->variance, true);
    });

    check('a variance inside the tolerance is not flagged', function() use ($plugin) {
        $link = $plugin->getLinks()->claim(Link::TYPE_INVOICE, 'order:3');
        $plugin->getLinks()->markSynced($link, 'zoho-2', 'INV-000002', 100.00, 100.002);

        return $link->getHasVariance(0.01) === false;
    });

    check('a failure increments the attempt count', function() use ($plugin) {
        $link = $plugin->getLinks()->claim(Link::TYPE_INVOICE, 'order:4');
        $plugin->getLinks()->markFailed($link, 'nope');
        $plugin->getLinks()->markFailed($link, 'nope again');

        return $link->attempts === 2 ?: "attempts = {$link->attempts}";
    });

    check('a synced link refuses to be reset for retry', function() use ($plugin) {
        $link = $plugin->getLinks()->find(Link::TYPE_INVOICE, 'order:2');

        return $plugin->getLinks()->resetForRetry($link) === false;
    });

    check('a failed link accepts a reset', function() use ($plugin) {
        $link = $plugin->getLinks()->find(Link::TYPE_INVOICE, 'order:4');

        return $plugin->getLinks()->resetForRetry($link) === true
            && $link->status === Link::STATUS_PENDING
            && $link->attempts === 0;
    });

    check('a link can be looked up by its Zoho id', function() use ($plugin) {
        return $plugin->getLinks()->findByZohoId(Link::TYPE_INVOICE, 'zoho-1')?->craftKey === 'order:2';
    });

    check('the key helper is the only shape used', function() {
        return Link::key('order', 42) === 'order:42' && (new Link(['craftKey' => 'order:42']))->getCraftId() === 42;
    });

    check('stats count the unreconciled separately from the failed', function() use ($plugin) {
        setSettings(['varianceTolerance' => 0.01]);
        $stats = $plugin->getLinks()->getStats();

        return $stats['variance'] === 1 && $stats['failed'] === 0 ?: json_encode($stats);
    });

    clearLinks();

    // =====================================================================
    section('Payload building');

    $product = makeProduct("ZO-$suffix", 25.00);
    $variant = $product->getDefaultVariant();
    $order = makeOrder([['variant' => $variant, 'qty' => 2]], true, 'zo-fixture@example.com');

    check('the fixture order is complete and priced', function() use ($order) {
        return $order->isCompleted && $order->getTotalPrice() > 0
            ?: 'total ' . $order->getTotalPrice();
    });

    check('a contact carries the billing address and a primary contact person', function() use ($plugin, $order) {
        $payload = $plugin->getDocuments()->buildContactPayload($order);

        return ($payload['billing_address']['city'] ?? null) === 'Charlotte'
            && ($payload['billing_address']['country'] ?? null) === 'United States'
            && ($payload['contact_persons'][0]['is_primary_contact'] ?? null) === true
            && ($payload['contact_persons'][0]['email'] ?? null) === 'zo-fixture@example.com'
            ?: json_encode($payload);
    });

    check('the country goes out as a name, not an ISO code', function() use ($plugin, $order) {
        $payload = $plugin->getDocuments()->buildContactPayload($order);

        return ($payload['billing_address']['country'] ?? null) !== 'US';
    });

    check('an order with a company name is a business customer', function() use ($plugin, $order) {
        setSettings(['inferBusinessCustomers' => true]);
        $payload = $plugin->getDocuments()->buildContactPayload($order);

        return ($payload['customer_sub_type'] ?? null) === 'business'
            && ($payload['company_name'] ?? null) === 'Fixture Supplies Ltd';
    });

    check('address line 2 and 3 are folded into street2', function() use ($plugin, $order) {
        $payload = $plugin->getDocuments()->buildContactPayload($order);

        return ($payload['billing_address']['street2'] ?? null) === 'Unit 3';
    });

    check('an invoice in adjustment mode totals to exactly what Commerce charged', function() use ($plugin, $order) {
        setSettings(['taxMode' => Settings::TAX_MODE_ADJUSTMENT]);
        $documents = $plugin->getDocuments();
        $payload = $documents->buildInvoicePayload($order, '123');

        return Money::equal($documents->projectedTotal($payload), Money::round($order->getTotalPrice()))
            ?: sprintf('projected %s vs %s', $documents->projectedTotal($payload), $order->getTotalPrice());
    });

    check('adjustment mode never puts a tax_id on a line', function() use ($plugin, $order) {
        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');

        foreach ($payload['line_items'] as $line) {
            if (isset($line['tax_id'])) {
                return 'a line carried a tax_id';
            }
        }

        return true;
    });

    check('mapped mode puts the mapped tax_id on the line', function() use ($plugin, $order, $variant) {
        $handle = $variant->getTaxCategory()->handle;
        setSettings(['taxMode' => Settings::TAX_MODE_MAPPED]);
        $plugin->getSettings()->setTaxMap([$handle => '55501']);

        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');
        $taxIds = array_column($payload['line_items'], 'tax_id');

        setSettings(['taxMode' => Settings::TAX_MODE_ADJUSTMENT]);

        return $taxIds === ['55501'] ?: json_encode($taxIds);
    });

    check('mapped mode adds no adjustment — Zoho is doing the arithmetic', function() use ($plugin, $order, $variant) {
        setSettings(['taxMode' => Settings::TAX_MODE_MAPPED]);
        $plugin->getSettings()->setTaxMap([$variant->getTaxCategory()->handle => '55501']);
        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');
        setSettings(['taxMode' => Settings::TAX_MODE_ADJUSTMENT]);

        return !isset($payload['adjustment']);
    });

    check('“let Zoho number it” sends no invoice number', function() use ($plugin, $order) {
        setSettings(['invoiceNumberSource' => 'zoho']);

        return $plugin->getDocuments()->documentNumber($order) === null;
    });

    check('a Craft-numbered invoice carries the order reference', function() use ($plugin, $order) {
        setSettings(['invoiceNumberSource' => 'reference']);
        $number = $plugin->getDocuments()->documentNumber($order);
        setSettings(['invoiceNumberSource' => 'zoho']);

        return $number === ($order->reference ?: $order->getShortNumber()) ?: var_export($number, true);
    });

    check('numbers are clipped to what Zoho will accept', function() use ($plugin, $order) {
        setSettings(['referenceSource' => 'reference']);

        return mb_strlen($plugin->getDocuments()->referenceNumber($order)) <= 100;
    });

    check('the invoice date is the site-local order date, not a UTC one', function() use ($plugin, $order) {
        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');
        $expected = (clone $order->dateOrdered)->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()))->format('Y-m-d');

        return ($payload['date'] ?? null) === $expected ?: ($payload['date'] ?? 'missing') . " vs $expected";
    });

    check('payment terms produce a due date', function() use ($plugin, $order) {
        setSettings(['paymentTermsDays' => 14]);
        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');
        setSettings(['paymentTermsDays' => 0]);

        return ($payload['payment_terms'] ?? null) === 14 && !empty($payload['due_date']);
    });

    check('a broken object template costs a blank note, not the invoice', function() use ($plugin, $order) {
        setSettings(['invoiceNotes' => '{{ object.thisDoesNotExist.atAll() }}']);
        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');
        setSettings(['invoiceNotes' => '']);

        return !isset($payload['notes']) ?: 'notes: ' . $payload['notes'];
    });

    check('a working object template renders against the order', function() use ($plugin, $order) {
        setSettings(['invoiceNotes' => 'Craft order {{ object.getShortNumber() }}']);
        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');
        setSettings(['invoiceNotes' => '']);

        return ($payload['notes'] ?? '') === 'Craft order ' . $order->getShortNumber() ?: json_encode($payload['notes'] ?? null);
    });

    check('custom fields use api_name for cf_ keys and label for the rest', function() use ($plugin, $order) {
        $plugin->getSettings()->setCustomFields([
            ['field' => 'cf_channel', 'template' => 'web'],
            ['field' => 'Channel label', 'template' => 'web'],
        ]);
        $payload = $plugin->getDocuments()->buildInvoicePayload($order, '123');
        $plugin->getSettings()->setCustomFields([]);

        $fields = $payload['custom_fields'] ?? [];

        return count($fields) === 2
            && isset($fields[0]['api_name'])
            && isset($fields[1]['label'])
            ?: json_encode($fields);
    });

    check('a sales order is the invoice body with a sales order number', function() use ($plugin, $order) {
        setSettings(['invoiceNumberSource' => 'reference']);
        $payload = $plugin->getDocuments()->buildSalesOrderPayload($order, '123');
        setSettings(['invoiceNumberSource' => 'zoho']);

        return isset($payload['salesorder_number'])
            && !isset($payload['invoice_number'])
            && !isset($payload['due_date']);
    });

    check('a mapped gateway wins over the default', function() use ($plugin, $order) {
        $transaction = addTransaction($order, TransactionRecord::TYPE_PURCHASE, 10.00);
        $plugin->getSettings()->setPaymentModeMap(['dummy' => 'banktransfer']);
        setSettings(['defaultPaymentMode' => 'creditcard']);
        $mode = $plugin->getDocuments()->paymentMode($transaction);

        return $mode === 'banktransfer' ?: $mode;
    });

    check('an unmapped gateway falls back to the default', function() use ($plugin, $order) {
        $transaction = addTransaction($order, TransactionRecord::TYPE_PURCHASE, 10.00);
        $plugin->getSettings()->setPaymentModeMap([]);
        setSettings(['defaultPaymentMode' => 'banktransfer']);
        $mode = $plugin->getDocuments()->paymentMode($transaction);
        setSettings(['defaultPaymentMode' => 'creditcard']);

        return $mode === 'banktransfer' ?: $mode;
    });

    check('a nonsense default payment mode is not passed through to Zoho', function() use ($plugin, $order) {
        $transaction = addTransaction($order, TransactionRecord::TYPE_PURCHASE, 10.00);
        setSettings(['defaultPaymentMode' => 'bitcoin']);
        $mode = $plugin->getDocuments()->paymentMode($transaction);
        setSettings(['defaultPaymentMode' => 'creditcard']);

        return in_array($mode, Settings::paymentModes(), true) ?: $mode;
    });

    check('a payment applies to the invoice it belongs to', function() use ($plugin, $order) {
        $transaction = addTransaction($order, TransactionRecord::TYPE_PURCHASE, 40.00);
        $payload = $plugin->getDocuments()->buildPaymentPayload($transaction, 'contact-1', 'invoice-1', 40.00);

        return ($payload['invoices'][0]['invoice_id'] ?? null) === 'invoice-1'
            && ($payload['invoices'][0]['amount_applied'] ?? null) === 40.00
            && ($payload['customer_id'] ?? null) === 'contact-1';
    });

    check('a credit note refund names the account the money left from', function() use ($plugin, $order) {
        $transaction = addTransaction($order, TransactionRecord::TYPE_REFUND, -15.00);
        $payload = $plugin->getDocuments()->buildCreditNoteRefundPayload($transaction, 'acct-9');

        return ($payload['from_account_id'] ?? null) === 'acct-9'
            && ($payload['amount'] ?? null) === 15.00
            ?: json_encode($payload);
    });

    // =====================================================================
    section('Syncing an order');

    clearLinks();
    connectFixture();
    setSettings(['taxMode' => Settings::TAX_MODE_ADJUSTMENT, 'syncItems' => false, 'markInvoiceSent' => true, 'emailInvoice' => false, 'syncPayments' => false, 'syncRefunds' => false]);

    $syncOrder = makeOrder([['variant' => $variant, 'qty' => 1]]);
    $syncTotal = Money::round($syncOrder->getTotalPrice());

    check('a full sync creates a contact then an invoice then marks it sent', function() use ($plugin, $syncOrder, $syncTotal) {
        $plugin->getAuth()->forgetAccessToken();
        setSettings(['reuseContactByEmail' => false]);
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-1', 'contact_name' => 'Dana Fixture']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-1', 'invoice_number' => 'INV-000123', 'total' => $syncTotal]]),
            zohoOk(['message' => 'Invoice status has been changed to Sent'], 200),
        ]);

        $result = $plugin->getSync()->syncOrder($syncOrder);

        return $result->getIsSuccessful()
            && $result->invoiceId === 'i-1'
            && $result->invoiceNumber === 'INV-000123'
            ?: $result->getSummary() . ' — ' . json_encode(sentRequests());
    });

    check('the invoice call went to the right endpoint with the customer attached', function() {
        $index = requestIndex('/invoices?');

        if ($index === null) {
            return 'no invoice call was made: ' . json_encode(sentRequests());
        }

        $body = sentBody($index);

        return ($body['customer_id'] ?? null) === 'c-1' ?: json_encode($body);
    });

    check('syncing the same order again sends nothing new', function() use ($plugin, $syncOrder) {
        mockZoho([]);

        $result = $plugin->getSync()->syncOrder($syncOrder);
        $steps = array_column($result->steps, 'outcome', 'step');

        return ($steps['invoice'] ?? null) === SyncResult::STEP_REUSED && sentRequests() === []
            ?: json_encode([$steps, sentRequests()]);
    });

    check('the link records both totals and reconciles', function() use ($plugin, $syncOrder, $syncTotal) {
        $link = $plugin->getLinks()->find(Link::TYPE_INVOICE, Link::key('order', $syncOrder->id));

        return $link !== null
            && $link->getIsSynced()
            && Money::equal((float)$link->craftTotal, $syncTotal)
            && $link->getHasVariance(0.01) === false
            ?: json_encode([$link?->craftTotal, $link?->zohoTotal, $link?->variance]);
    });

    check('a Zoho total that disagrees is recorded as a variance, not hidden', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-2']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-2', 'invoice_number' => 'INV-000124', 'total' => Money::round($order->getTotalPrice()) + 1.25]]),
            zohoOk([], 200),
        ]);

        $result = $plugin->getSync()->syncOrder($order);
        $link = $plugin->getLinks()->find(Link::TYPE_INVOICE, Link::key('order', $order->id));

        return $result->getHasVariance()
            && $link->getIsSynced()
            && abs((float)$link->variance - 1.25) < 0.001
            ?: json_encode([$result->variance, $link?->variance, $link?->status]);
    });

    check('an unreconciled invoice is still marked synced, so nobody creates a second one', function() use ($plugin) {
        $links = $plugin->getLinks()->getLinks(['type' => Link::TYPE_INVOICE, 'hasVariance' => true]);

        foreach ($links as $link) {
            if ($link->status !== Link::STATUS_SYNCED) {
                return 'an unreconciled link was left as ' . $link->status;
            }
        }

        return $links !== [] ?: 'no unreconciled links to check';
    });

    check('blockOnVariance reports the failure without unlinking the invoice', function() use ($plugin, $variant) {
        setSettings(['blockOnVariance' => true]);
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-3']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-3', 'total' => Money::round($order->getTotalPrice()) + 5]]),
            zohoOk([], 200),
        ]);

        $result = $plugin->getSync()->syncOrder($order);
        $link = $plugin->getLinks()->find(Link::TYPE_INVOICE, Link::key('order', $order->id));
        setSettings(['blockOnVariance' => false]);

        return $result->getIsSuccessful() === false && $link->getIsSynced() === true
            ?: json_encode([$result->getSummary(), $link?->status]);
    });

    check('an invoice failure leaves a failed link with the reason on it', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-4']]),
            zohoError(400, 4000, 'Invalid value passed for customer_id'),
        ]);

        $result = $plugin->getSync()->syncOrder($order);
        $link = $plugin->getLinks()->find(Link::TYPE_INVOICE, Link::key('order', $order->id));

        return $result->getIsSuccessful() === false
            && $link->status === Link::STATUS_FAILED
            && str_contains((string)$link->lastError, 'customer_id')
            ?: json_encode([$result->getSummary(), $link?->status, $link?->lastError]);
    });

    check('a contact failure stops before an invoice can be created without one', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([tokenResponse(), zohoError(400, 4000, 'contact_name is required')]);

        $result = $plugin->getSync()->syncOrder($order);

        return $result->getIsSuccessful() === false
            && count($result->steps) === 1
            && $plugin->getLinks()->find(Link::TYPE_INVOICE, Link::key('order', $order->id)) === null
            ?: json_encode($result->steps);
    });

    check('an existing Zoho contact is adopted rather than duplicated', function() use ($plugin, $variant) {
        setSettings(['reuseContactByEmail' => true]);
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, 'already-there@example.com');
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contacts' => [['contact_id' => 'existing-1', 'contact_name' => 'Already There']]], 200),
            zohoOk(['invoice' => ['invoice_id' => 'i-5', 'total' => Money::round($order->getTotalPrice())]]),
            zohoOk([], 200),
        ]);

        $result = $plugin->getSync()->syncOrder($order);
        setSettings(['reuseContactByEmail' => false]);

        return $result->contactId === 'existing-1'
            && !in_array('POST /contacts', array_map(static fn($r) => preg_replace('/\?.*/', '', $r), sentRequests()), true)
            ?: json_encode([$result->contactId, sentRequests()]);
    });

    check('a duplicate-name rejection adopts the existing contact instead of failing forever', function() use ($plugin, $variant) {
        setSettings(['reuseContactByEmail' => false]);
        $order = makeOrder([['variant' => $variant, 'qty' => 1]], true, 'clash@example.com');
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoError(400, 1001, 'Contact name already exists'),
            zohoOk(['contacts' => [['contact_id' => 'clash-1', 'contact_name' => 'Dana Fixture']]], 200),
            zohoOk(['invoice' => ['invoice_id' => 'i-6', 'total' => Money::round($order->getTotalPrice())]]),
            zohoOk([], 200),
        ]);

        $result = $plugin->getSync()->syncOrder($order);

        return $result->contactId === 'clash-1' && $result->getIsSuccessful()
            ?: json_encode([$result->contactId, $result->getSummary()]);
    });

    check('a failure to leave draft is a warning, not a lost invoice', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-7']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-7', 'total' => Money::round($order->getTotalPrice())]]),
            zohoError(400, 4000, 'Invoice cannot be marked as sent'),
        ]);

        $result = $plugin->getSync()->syncOrder($order);
        $link = $plugin->getLinks()->find(Link::TYPE_INVOICE, Link::key('order', $order->id));

        return $result->getIsSuccessful() && $link->getIsSynced()
            ?: json_encode([$result->getSummary(), $link?->status]);
    });

    // =====================================================================
    section('Payments and refunds');

    check('a payment is recorded against the invoice', function() use ($plugin, $variant) {
        setSettings(['syncPayments' => true, 'syncRefunds' => false, 'reuseContactByEmail' => false]);
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $total = Money::round($order->getTotalPrice());
        addTransaction($order, TransactionRecord::TYPE_PURCHASE, $total);
        $order = Order::find()->id($order->id)->status(null)->one();

        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-8']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-8', 'total' => $total]]),
            zohoOk([], 200),
            zohoOk(['payment' => ['payment_id' => 'p-1', 'payment_number' => '1', 'amount' => $total]]),
        ]);

        $result = $plugin->getSync()->syncOrder($order);
        $body = sentBody(requestIndex('customerpayments') ?? -1);

        return $result->getIsSuccessful()
            && ($body['invoices'][0]['invoice_id'] ?? null) === 'i-8'
            && Money::equal((float)($body['invoices'][0]['amount_applied'] ?? 0), $total)
            ?: json_encode([$result->getSummary(), sentRequests(), $body]);
    });

    check('an authorization is not treated as money', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $total = Money::round($order->getTotalPrice());
        addTransaction($order, TransactionRecord::TYPE_AUTHORIZE, $total);
        $order = Order::find()->id($order->id)->status(null)->one();

        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-9']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-9', 'total' => $total]]),
            zohoOk([], 200),
        ]);

        $plugin->getSync()->syncOrder($order);

        foreach (sentRequests() as $request) {
            if (str_contains($request, 'customerpayments')) {
                return 'an authorization was recorded as a payment';
            }
        }

        return true;
    });

    check('a payment is never applied for more than the invoice is worth', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $total = Money::round($order->getTotalPrice());
        // Two full captures — a data state Commerce permits and Zoho would reject as an
        // over-application.
        addTransaction($order, TransactionRecord::TYPE_CAPTURE, $total);
        addTransaction($order, TransactionRecord::TYPE_CAPTURE, $total);
        $order = Order::find()->id($order->id)->status(null)->one();

        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-10']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-10', 'total' => $total]]),
            zohoOk([], 200),
            zohoOk(['payment' => ['payment_id' => 'p-2', 'amount' => $total]]),
        ]);

        $plugin->getSync()->syncOrder($order);

        $applied = 0.0;

        foreach (sentRequests() as $index => $request) {
            if (str_contains($request, 'customerpayments')) {
                $applied += (float)(sentBody($index)['invoices'][0]['amount_applied'] ?? 0);
            }
        }

        return $applied <= $total + 0.005 ?: "applied $applied against $total";
    });

    check('a refund becomes a credit note, opened, with the cash movement recorded', function() use ($plugin, $variant) {
        setSettings(['syncPayments' => false, 'syncRefunds' => true, 'depositAccountId' => 'acct-1']);
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $total = Money::round($order->getTotalPrice());
        addTransaction($order, TransactionRecord::TYPE_REFUND, -5.00);
        $order = Order::find()->id($order->id)->status(null)->one();

        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-11']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-11', 'total' => $total]]),
            zohoOk([], 200),
            zohoOk(['creditnote' => ['creditnote_id' => 'cn-1', 'creditnote_number' => 'CN-001', 'total' => 5.00]]),
            zohoOk([], 200),
            zohoOk(['creditnote_refund' => ['creditnote_refund_id' => 'r-1']]),
        ]);

        $plugin->getSync()->syncOrder($order);
        $requests = implode(' | ', sentRequests());

        return str_contains($requests, '/creditnotes')
            && str_contains($requests, 'status/open')
            && str_contains($requests, 'refunds')
            ?: $requests;
    });

    check('without a deposit account the credit note still stands, minus the cash entry', function() use ($plugin, $variant) {
        setSettings(['depositAccountId' => '']);
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $total = Money::round($order->getTotalPrice());
        addTransaction($order, TransactionRecord::TYPE_REFUND, -5.00);
        $order = Order::find()->id($order->id)->status(null)->one();

        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-12']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-12', 'total' => $total]]),
            zohoOk([], 200),
            zohoOk(['creditnote' => ['creditnote_id' => 'cn-2', 'total' => 5.00]]),
            zohoOk([], 200),
        ]);

        $result = $plugin->getSync()->syncOrder($order);
        $steps = array_column($result->steps, 'outcome', 'step');
        setSettings(['depositAccountId' => '', 'syncRefunds' => false]);

        return ($steps['refund-cash'] ?? null) === SyncResult::STEP_SKIPPED
            && str_contains(implode(' | ', sentRequests()), '/creditnotes')
            ?: json_encode($result->steps);
    });

    // =====================================================================
    section('Eligibility and backfill');

    check('an incomplete order is not eligible', function() use ($plugin, $variant) {
        $cart = makeOrder([['variant' => $variant, 'qty' => 1]], false);
        $reason = null;

        return $plugin->getSync()->shouldSync($cart, $reason) === false && $reason !== null;
    });

    check('an order below the minimum is skipped', function() use ($plugin, $syncOrder) {
        setSettings(['minimumOrderTotal' => 1000000.0]);
        $eligible = $plugin->getSync()->shouldSync($syncOrder);
        setSettings(['minimumOrderTotal' => 0.0]);

        return $eligible === false;
    });

    check('a status filter excludes orders outside it', function() use ($plugin, $syncOrder) {
        setSettings(['eligibleStatusHandles' => ['no-such-status']]);
        $eligible = $plugin->getSync()->shouldSync($syncOrder);
        setSettings(['eligibleStatusHandles' => []]);

        return $eligible === false;
    });

    check('syncing is still possible by hand on an ineligible order', function() use ($plugin, $variant) {
        setSettings(['minimumOrderTotal' => 1000000.0, 'reuseContactByEmail' => false]);
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['contact' => ['contact_id' => 'c-13']]),
            zohoOk(['invoice' => ['invoice_id' => 'i-13', 'total' => Money::round($order->getTotalPrice())]]),
            zohoOk([], 200),
        ]);

        $result = $plugin->getSync()->syncOrder($order, true);
        setSettings(['minimumOrderTotal' => 0.0]);

        return $result->getIsSuccessful() ?: $result->getSummary();
    });

    check('an already-synced order is not offered for backfill', function() use ($plugin, $syncOrder) {
        $ids = array_map(static fn(Order $o) => $o->id, $plugin->getSync()->getUnsyncedOrders(null, 200));

        return !in_array($syncOrder->id, $ids, true) ?: 'a synced order was queued again';
    });

    check('an order that has never been synced is offered for backfill', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $ids = array_map(static fn(Order $o) => $o->id, $plugin->getSync()->getUnsyncedOrders(null, 200));

        return in_array($order->id, $ids, true) ?: 'an unsynced order was not queued';
    });

    check('an order out of retries is left alone', function() use ($plugin, $variant) {
        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $link = $plugin->getLinks()->claim(Link::TYPE_INVOICE, Link::key('order', $order->id), $order->id);

        for ($i = 0; $i < $plugin->getSettings()->maxAttempts; $i++) {
            $plugin->getLinks()->markFailed($link, 'nope');
        }

        $ids = array_map(static fn(Order $o) => $o->id, $plugin->getSync()->getUnsyncedOrders(null, 200));

        return !in_array($order->id, $ids, true) ?: 'an exhausted order was queued again';
    });

    check('a preview is built by the same code the sync uses', function() use ($plugin, $syncOrder) {
        $preview = $plugin->getSync()->preview($syncOrder);

        return Money::equal($preview['projectedTotal'], $preview['craftTotal'])
            && isset($preview['invoice']['line_items'])
            ?: json_encode([$preview['projectedTotal'], $preview['craftTotal']]);
    });

    // =====================================================================
    section('Logging');

    check('a client secret never reaches the log', function() {
        $redacted = LogService::redact('{"client_secret":"super-secret","grant_type":"refresh_token"}');

        return !str_contains($redacted, 'super-secret') && str_contains($redacted, 'grant_type')
            ?: $redacted;
    });

    check('a form-encoded refresh token is redacted too', function() {
        $redacted = LogService::redact('grant_type=refresh_token&refresh_token=1000.abc.def&client_id=x');

        return !str_contains($redacted, '1000.abc.def') ?: $redacted;
    });

    check('an Authorization header is redacted', function() {
        $redacted = LogService::redact('Authorization: Zoho-oauthtoken 1000.livetoken');

        return !str_contains($redacted, 'livetoken') ?: $redacted;
    });

    check('the API writes a log row per call', function() use ($plugin) {
        $plugin->getLog()->clear();
        $plugin->getAuth()->forgetAccessToken();
        setSettings(['loggingEnabled' => true]);
        mockZoho([tokenResponse(), zohoOk(['contacts' => []], 200)]);

        $plugin->getApi()->get('contacts', [], ['action' => 'log-check']);

        return $plugin->getLog()->count(['action' => 'log-check']) === 1
            ?: 'rows: ' . $plugin->getLog()->count(['action' => 'log-check']);
    });

    check('a failed call is logged at error level', function() use ($plugin) {
        $plugin->getLog()->clear();
        $plugin->getAuth()->forgetAccessToken();
        mockZoho([tokenResponse(), zohoError(400, 4000, 'nope')]);

        try {
            $plugin->getApi()->get('contacts', [], ['action' => 'log-error']);
        } catch (ZohoApiException) {
        }

        return $plugin->getLog()->count(['level' => LogEntry::LEVEL_ERROR]) >= 1;
    });

    check('logging off writes nothing', function() use ($plugin) {
        $plugin->getLog()->clear();
        setSettings(['loggingEnabled' => false]);
        $plugin->getLog()->write('ignored', ['summary' => 'x']);
        setSettings(['loggingEnabled' => true]);

        return $plugin->getLog()->count() === 0;
    });

    check('pruning drops entries past the retention window', function() use ($plugin) {
        $plugin->getLog()->clear();
        $plugin->getLog()->write('old', ['summary' => 'old']);

        Craft::$app->getDb()->createCommand()->update(
            Table::LOG,
            ['dateCreated' => craft\helpers\Db::prepareDateForDb((new DateTime())->modify('-90 days'))],
            ['action' => 'old']
        )->execute();

        $plugin->getLog()->write('new', ['summary' => 'new']);

        return $plugin->getLog()->prune(30) === 1 && $plugin->getLog()->count() === 1;
    });

    // =====================================================================
    section('Twig');

    check('craft.zo reports the invoice number for an order', function() use ($plugin, $syncOrder) {
        $variable = new justinholtweb\zo\twig\ZoVariable();

        return $variable->invoiceNumberFor($syncOrder) === 'INV-000123'
            ?: var_export($variable->invoiceNumberFor($syncOrder), true);
    });

    check('craft.zo returns nothing for an order that was never synced', function() {
        $variable = new justinholtweb\zo\twig\ZoVariable();

        return $variable->invoiceNumberFor(999999999) === null;
    });

    // =====================================================================
    section('One edition');

    check('the plugin declares a single edition', function() {
        // Craft falls back to the first edition when a stored one is unknown, so an install that
        // predates this still loads — but a second edition reappearing here is a regression.
        $editions = Plugin::editions();

        return count($editions) === 1 ?: 'editions: ' . implode(', ', $editions);
    });

    check('item sync runs when it is switched on', function() use ($variant) {
        clearLinks();
        resetRateLimiter();
        setSettings(['syncItems' => true, 'itemMatchBy' => 'sku']);
        Plugin::getInstance()->getAuth()->forgetAccessToken();
        mockZoho([
            tokenResponse(),
            zohoOk(['items' => []], 200),
            zohoOk(['item' => ['item_id' => '77001', 'name' => 'Fixture item']]),
        ]);

        $order = makeOrder([['variant' => $variant, 'qty' => 1]]);
        $ids = Plugin::getInstance()->getItems()->syncForOrder($order);

        setSettings(['syncItems' => false]);
        clearMock();

        return $ids === [$variant->id => '77001'] ?: json_encode($ids);
    });

    check('the log keeps request and response bodies', function() use ($plugin) {
        $plugin->getLog()->clear();
        setSettings(['loggingEnabled' => true, 'logPayloads' => true]);
        $plugin->getLog()->write('payload-check', ['request' => '{"a":1}', 'response' => '{"b":2}']);

        $entries = $plugin->getLog()->getEntries(['action' => 'payload-check'], 1);
        $entry = $plugin->getLog()->getEntryById($entries[0]->id);

        return ($entry->request === '{"a":1}' && $entry->response === '{"b":2}')
            ?: json_encode([$entry->request, $entry->response]);
    });
} finally {
    section('Cleanup');

    clearMock();

    $elements = Craft::$app->getElements();

    foreach ($createdOrders as $fixtureOrder) {
        try {
            Craft::$app->getDb()->createCommand()->delete(Table::LINKS, ['elementId' => $fixtureOrder->id])->execute();
            $elements->deleteElement($fixtureOrder, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    try {
        Craft::$app->getDb()->createCommand()->delete(Table::LINKS)->execute();
        Craft::$app->getDb()->createCommand()->delete(Table::LOG)->execute();
    } catch (Throwable $e) {
        echo "  ! could not clear Zo's tables: {$e->getMessage()}\n";
    }

    try {
        // Anything Zo queued during the run points at an order that no longer exists.
        Craft::$app->getDb()->createCommand()->delete(craft\db\Table::QUEUE, [
            'like', 'description', 'Zoho Books',
        ])->execute();
    } catch (Throwable $e) {
        echo "  ! could not clear queued jobs: {$e->getMessage()}\n";
    }

    try {
        Plugin::getInstance()->getAuth()->forgetAccessToken();
        applySettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    echo "  ✓ fixtures removed, settings restored\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
