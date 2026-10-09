<?php
/**
 * Shared bootstrap for alerts.php and orders.php: Craft, the check runner, a captured mailer, the
 * Zoho mock transport and order fixtures.
 *
 * Settings are only ever changed in memory here — nothing is written to project config — and
 * every fixture is registered for removal in a shutdown function, pass or fail.
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
use craft\elements\User;
use craft\mail\Mailer;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\Plugin;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function finish(): never
{
    global $passed, $failed;

    echo "\n$passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
}

// craft-penny (a sibling in this shared harness) fatals every element save; detached in-process.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$suffix = bin2hex(random_bytes(3));
$cleanup = ['orders' => [], 'products' => [], 'users' => [], 'linkKeys' => []];

register_shutdown_function(function() use (&$cleanup, $plugin, $settings, $originalSettings) {
    $db = Craft::$app->getDb();
    $elements = Craft::$app->getElements();

    foreach ($cleanup['orders'] as $order) {
        try {
            $db->createCommand()->delete(Table::LINKS, ['elementId' => $order->id])->execute();
            $elements->deleteElement($order, true);
        } catch (Throwable $e) {
            echo "  ! could not delete order {$order->id}: {$e->getMessage()}\n";
        }
    }

    foreach (array_merge($cleanup['products'], $cleanup['users']) as $element) {
        try {
            $elements->deleteElement($element, true);
        } catch (Throwable $e) {
            echo "  ! could not delete {$element->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($cleanup['linkKeys'] as $key) {
        $db->createCommand()->delete(Table::LINKS, ['like', 'craftKey', $key . '%', false])->execute();
    }

    // Contact links of fixture customers, and anything this run queued.
    $db->createCommand()->delete(Table::LINKS, ['like', 'craftKey', '%zo-fixture-%', false])->execute();
    $db->createCommand()->delete(Table::ALERTS)->execute();
    $db->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'Zoho Books', false])->execute();
    $db->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'Zoho Books', false])->execute();

    $plugin->getAuth()->clientConfig = [];
    $plugin->getApi()->clientConfig = [];
    $plugin->getAuth()->forgetAccessToken();
    $settings->setAttributes($originalSettings, false);
});

// Completing a fixture order would otherwise queue a real sync job per order.
$settings->setAttributes([
    'autoSyncOnComplete' => false,
    'autoSyncOnPaid' => false,
    'syncOnStatusHandles' => [],
    'syncViaQueue' => true,
], false);

// The real mailer on Symfony's null transport: the compose/send path runs in full, and the result
// does not depend on whether the harness's Mailpit is up. Everything sent is captured.
Craft::$app->getMailer()->setTransport(new Symfony\Component\Mailer\Transport\NullTransport());
$mail = [];
$mailFails = false;
Event::on(Mailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $e) use (&$mailFails) {
    if ($mailFails) {
        $e->isValid = false;
    }
});
Event::on(Mailer::class, BaseMailer::EVENT_AFTER_SEND, function(MailEvent $e) use (&$mail) {
    if ($e->isSuccessful) {
        $to = (array)$e->message->getTo();
        $mail[] = [
            'to' => array_map(static fn($k, $v) => is_string($k) ? $k : (string)$v, array_keys($to), $to),
            'subject' => (string)$e->message->getSubject(),
            // The decoded text part: the wire form is quoted-printable, which splits lines.
            'body' => (string)$e->message->getSymfonyEmail()->getTextBody(),
        ];
    }
});

// ---------------------------------------------------------------------------------------------
// Zoho, mocked: the harness has no outbound network.

$journal = [];

/**
 * @param array<int, GuzzleResponse|Throwable> $responses
 */
function mockZoho(array $responses): void
{
    global $journal, $plugin;

    $journal = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($journal));

    $plugin->getApi()->clientConfig = ['handler' => $stack];
    $plugin->getAuth()->clientConfig = ['handler' => $stack];

    $orgId = $plugin->getSettings()->getParsedOrganizationId();
    $minute = (int)floor(time() / 60);

    foreach ([$minute - 1, $minute] as $slot) {
        Craft::$app->getCache()->delete('zo:rate:' . $orgId . ':' . $slot);
    }
}

function zohoOk(array $payload, int $status = 201): GuzzleResponse
{
    return new GuzzleResponse($status, ['Content-Type' => 'application/json'], json_encode(array_merge(['code' => 0, 'message' => 'success'], $payload)));
}

function zohoError(int $status, int $code, string $message): GuzzleResponse
{
    return new GuzzleResponse($status, ['Content-Type' => 'application/json'], json_encode(['code' => $code, 'message' => $message]));
}

function tokenResponse(string $accessToken = 'access-token-1'): GuzzleResponse
{
    return new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
        'access_token' => $accessToken,
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ]));
}

/**
 * @return string[] `METHOD path` for every request Zo made
 */
function sentRequests(): array
{
    global $journal;

    return array_map(static fn(array $e) => $e['request']->getMethod() . ' ' . $e['request']->getUri()->getPath(), $journal);
}

function requestIndex(string $needle): ?int
{
    foreach (sentRequests() as $i => $request) {
        if (str_contains($request, $needle)) {
            return $i;
        }
    }

    return null;
}

function sentBody(?int $index): array
{
    global $journal;

    $decoded = $index !== null && isset($journal[$index]) ? json_decode((string)$journal[$index]['request']->getBody(), true) : null;

    return is_array($decoded) ? $decoded : [];
}

function connectFixture(): void
{
    global $plugin;

    $plugin->getSettings()->setAttributes([
        'clientId' => 'fixture-client',
        'clientSecret' => 'fixture-secret-abcdef',
        'refreshToken' => 'fixture-refresh-123456',
        'organizationId' => '10234695',
        'dataCenter' => 'com',
    ], false);

    $plugin->getAuth()->forgetAccessToken();
}

// ---------------------------------------------------------------------------------------------
// Commerce fixtures.

function makeVariant(float $price = 20.0): Variant
{
    global $cleanup, $suffix;

    $product = new Product();
    $product->typeId = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0]->id;
    $product->title = "Zo fixture $suffix";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = 'ZO-FIX-' . $suffix . '-' . bin2hex(random_bytes(2));
    $variant->basePrice = $price;
    $variant->isDefault = true;
    $product->setVariants([$variant]);

    Craft::$app->getElements()->saveElement($product, true, true, false)
        or throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    $cleanup['products'][] = $product;

    return $product->getVariants()[0];
}

function makeOrder(Variant $variant, bool $complete = true): Order
{
    global $cleanup;

    $commerce = Commerce::getInstance();
    $order = new Order();
    $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
    $order->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $order->number = $commerce->getCarts()->generateCartNumber();
    // A unique email per order: Zo reuses a contact across one customer's orders.
    $order->setEmail('zo-fixture-' . bin2hex(random_bytes(5)) . '@example.com');

    $gateway = $commerce->getGateways()->getAllGateways()->firstWhere('handle', 'dummy');

    if ($gateway !== null) {
        $order->gatewayId = $gateway->id;
    }

    Craft::$app->getElements()->saveElement($order, false, true, false) or throw new RuntimeException('Could not save order');
    $cleanup['orders'][] = $order;

    $order->setLineItems([$commerce->getLineItems()->createLineItem($order, $variant->id, [], 1)]);
    $address = [
        'fullName' => 'Dana Fixture',
        'addressLine1' => '742 Evergreen Terrace',
        'locality' => 'Charlotte',
        'administrativeArea' => 'NC',
        'postalCode' => '28202',
        'countryCode' => 'US',
    ];
    $order->setShippingAddress($address);
    $order->setBillingAddress($address);
    Craft::$app->getElements()->saveElement($order, false, true, false) or throw new RuntimeException('Could not save order lines');

    if ($complete) {
        $order->markAsComplete();
    }

    return $order;
}

function addTransaction(Order $order, string $type, float $amount, mixed $response = null): craft\commerce\models\Transaction
{
    $transactions = Commerce::getInstance()->getTransactions();
    $transaction = $transactions->createTransaction($order);
    $transaction->type = $type;
    $transaction->status = TransactionRecord::STATUS_SUCCESS;
    $transaction->amount = $amount;
    $transaction->paymentAmount = $amount;
    $transaction->reference = 'ref-' . bin2hex(random_bytes(4));
    $transaction->response = $response;
    $transactions->saveTransaction($transaction);

    return $transaction;
}

function reloadOrder(Order $order): Order
{
    return Order::find()->id($order->id)->status(null)->one();
}

// ---------------------------------------------------------------------------------------------
// HTTP against the harness's own web server, as a real signed-in user.

function makeUser(string $handle, array $permissions): array
{
    global $cleanup, $suffix;

    $password = 'Zo-' . bin2hex(random_bytes(6));
    $user = new User(['username' => "zo-$handle-$suffix", 'email' => "zo-$handle-$suffix@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($user, false) or throw new RuntimeException('Could not save user');
    Craft::$app->getUsers()->activateUser($user);
    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $cleanup['users'][] = $user;

    return [$user, $password];
}

/**
 * A signed-in client. Returns `fn(action, params, method, withCsrf, json)`.
 */
function client(?string $username, ?string $password): Closure
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 60]);
    $accept = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=admin/actions/users/session-info', ['headers' => $accept])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $http->post('index.php?p=admin/actions/users/login', ['headers' => $accept, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
            or throw new RuntimeException("Could not sign in as $username");
    }

    return static function(string $action, array $params = [], string $method = 'POST', bool $withCsrf = true, bool $json = false) use ($http, $accept, $csrf) {
        $options = ['headers' => $accept];

        if ($method === 'POST' && $json) {
            $options['json'] = $params;
            $options['headers'] += $withCsrf ? ['X-CSRF-Token' => $csrf()] : [];
        } elseif ($method === 'POST') {
            $options['form_params'] = $params + ($withCsrf ? ['CRAFT_CSRF_TOKEN' => $csrf()] : []);
        }

        return $http->request($method, "index.php?p=admin/actions/$action", $options);
    };
}
