<?php
/**
 * The Orders index (Zoho column, "Zoho Books status" condition rule, "Sync to Zoho Books" action)
 * and per-gateway deposit accounts with processor fees as bank charges.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-zo/tests/integration/orders.php
 *
 * The condition rule is applied to a real order query and checked against the column's own
 * answer for the same orders; the column and the action are also exercised over HTTP as a
 * signed-in user. Zoho is a Guzzle MockHandler. Settings stay in memory.
 */

require __DIR__ . '/_support.php';

use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\Order;
use craft\commerce\models\Transaction;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\elements\User;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\elements\actions\SyncToZoho;
use justinholtweb\zo\elements\conditions\ZohoStatusConditionRule;
use justinholtweb\zo\events\ProcessorFeeEvent;
use justinholtweb\zo\helpers\Money;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\models\Settings;
use justinholtweb\zo\services\Documents;
use justinholtweb\zo\services\Links;

$links = $plugin->getLinks();
$documents = $plugin->getDocuments();
$variant = makeVariant(20.0);
connectFixture();
$settings->setAttributes(['varianceTolerance' => 0.01, 'reuseContactByEmail' => false, 'markInvoiceSent' => true, 'emailInvoice' => false, 'syncItems' => false, 'documentType' => Settings::DOCUMENT_INVOICE], false);

// ---------------------------------------------------------------------------------------------
section('Order status: one order in each state');

$fixtures = [];
$link = fn(Order $o, string $type, ?string $key = null) => $links->claim($type, $key ?? Link::key('order', (int)$o->id), $o->id);

$o = makeOrder($variant);
$links->markSynced($link($o, Link::TYPE_INVOICE), 'i-1', 'INV-1', 20.0, 20.0);
$fixtures[Link::ORDER_SYNCED] = $o;

$o = makeOrder($variant);
$links->markSynced($link($o, Link::TYPE_INVOICE), 'i-2', 'INV-2', 20.0, 20.45);
$fixtures[Link::ORDER_NOT_RECONCILED] = $o;

$o = makeOrder($variant);
$links->markFailed($link($o, Link::TYPE_INVOICE), 'Zoho Books: no');
$fixtures[Link::ORDER_FAILED] = $o;

// Invoice in the books, its payment not: that order is not done.
$o = makeOrder($variant);
$links->markSynced($link($o, Link::TYPE_INVOICE), 'i-4', 'INV-4', 20.0, 20.0);
$links->markFailed($link($o, Link::TYPE_PAYMENT, Link::key('transaction', 'fixture-' . $o->id)), 'Zoho Books: over-applied');
$fixtures['failedPayment'] = $o;

// The customer could not be created, so no invoice link was ever claimed.
$o = makeOrder($variant);
$links->markFailed($links->claim(Link::TYPE_CONTACT, 'email:' . $o->getEmail(), $o->getCustomer()?->id), 'Zoho Books: invalid contact');
$fixtures['failedContact'] = $o;

$o = makeOrder($variant);
$link($o, Link::TYPE_INVOICE);
$fixtures[Link::ORDER_PENDING] = $o;

$o = makeOrder($variant);
$links->markSkipped($link($o, Link::TYPE_INVOICE), 'Below the minimum');
$fixtures[Link::ORDER_SKIPPED] = $o;

$fixtures[Link::ORDER_NONE] = makeOrder($variant);

$expected = [
    Link::ORDER_SYNCED => Link::ORDER_SYNCED,
    Link::ORDER_NOT_RECONCILED => Link::ORDER_NOT_RECONCILED,
    Link::ORDER_FAILED => Link::ORDER_FAILED,
    'failedPayment' => Link::ORDER_FAILED,
    'failedContact' => Link::ORDER_FAILED,
    Link::ORDER_PENDING => Link::ORDER_PENDING,
    Link::ORDER_SKIPPED => Link::ORDER_SKIPPED,
    Link::ORDER_NONE => Link::ORDER_NONE,
];
$ids = array_map(static fn(Order $o) => (int)$o->id, $fixtures);

foreach ($expected as $name => $status) {
    check("$name reads as $status", function() use ($links, $fixtures, $name, $status) {
        $got = $links->orderStatuses([$fixtures[$name]->id])[$fixtures[$name]->id] ?? null;

        return $got === $status ?: "got $got";
    });
}

check('the SQL sets partition the orders exactly as the column does', function() use ($links, $ids) {
    $statuses = $links->orderStatuses($ids);
    $problems = [];

    foreach (array_keys(Links::orderStatusOptions()) as $status) {
        $sql = Order::find()->id($ids)->status(null)->andWhere($links->orderStatusCondition($status))->ids();
        $php = array_keys(array_filter($statuses, static fn($s) => $s === $status));
        sort($sql);
        sort($php);

        if (array_map('intval', $sql) !== $php) {
            $problems[] = "$status: sql " . json_encode($sql) . ' php ' . json_encode($php);
        }
    }

    return $problems === [] ?: implode('; ', $problems);
});

check('the tolerance decides what is not reconciled', function() use ($links, $fixtures, $settings) {
    $settings->varianceTolerance = 1.00;
    $id = $fixtures[Link::ORDER_NOT_RECONCILED]->id;
    $php = $links->orderStatuses([$id])[$id];
    $sql = Order::find()->id($id)->status(null)->andWhere($links->orderStatusCondition(Link::ORDER_SYNCED))->exists();
    $settings->varianceTolerance = 0.01;

    return $php === Link::ORDER_SYNCED && $sql ?: "php $php, sql " . var_export($sql, true);
});

check('an unknown status matches nothing rather than everything', function() use ($links, $ids) {
    return Order::find()->id($ids)->status(null)->andWhere($links->orderStatusCondition('bogus'))->count() == 0 ?: 'matched';
});

// ---------------------------------------------------------------------------------------------
section('“Zoho Books status” condition rule');

$makeRule = function(array $values, string $operator = 'in'): ZohoStatusConditionRule {
    $condition = Craft::$app->getConditions()->createCondition(['class' => OrderCondition::class, 'elementType' => Order::class]);

    /** @var ZohoStatusConditionRule $rule */
    $rule = $condition->createConditionRule(['class' => ZohoStatusConditionRule::class, 'values' => $values, 'operator' => $operator]);

    return $rule;
};

check('it is offered on order conditions', function() {
    $condition = Craft::$app->getConditions()->createCondition(['class' => OrderCondition::class, 'elementType' => Order::class]);
    $types = array_map(static fn($r) => get_class($r), $condition->getSelectableConditionRules());

    return in_array(ZohoStatusConditionRule::class, $types, true) ?: 'missing';
});

check('“is one of failed, not reconciled” narrows an order query to exactly those', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id($ids)->status(null);
    $makeRule([Link::ORDER_FAILED, Link::ORDER_NOT_RECONCILED])->modifyQuery($query);
    $got = array_map('intval', $query->ids());
    $want = [(int)$fixtures[Link::ORDER_FAILED]->id, (int)$fixtures['failedPayment']->id, (int)$fixtures['failedContact']->id, (int)$fixtures[Link::ORDER_NOT_RECONCILED]->id];
    sort($got);
    sort($want);

    return $got === $want ?: json_encode(['got' => $got, 'want' => $want]);
});

check('“is not one of synced” keeps everything else, including never-synced orders', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id($ids)->status(null);
    $makeRule([Link::ORDER_SYNCED], 'ni')->modifyQuery($query);
    $got = array_map('intval', $query->ids());

    return count($got) === count($ids) - 1 && !in_array((int)$fixtures[Link::ORDER_SYNCED]->id, $got, true) && in_array((int)$fixtures[Link::ORDER_NONE]->id, $got, true)
        ?: json_encode($got);
});

check('matchElement agrees with the query for every fixture', function() use ($makeRule, $fixtures) {
    $rule = $makeRule([Link::ORDER_PENDING, Link::ORDER_NONE]);
    $wrong = [];

    foreach ($fixtures as $name => $order) {
        $want = in_array($name, [Link::ORDER_PENDING, Link::ORDER_NONE], true);

        if ($rule->matchElement($order) !== $want) {
            $wrong[] = $name;
        }
    }

    return $wrong === [] ?: implode(', ', $wrong);
});

check('a stale choice is kept as chosen, and the config round-trips it', function() use ($makeRule) {
    $rule = $makeRule([Link::ORDER_FAILED, 'renamed-status']);
    $again = Craft::$app->getConditions()->createConditionRule($rule->getConfig());

    return $rule->getValues() === [Link::ORDER_FAILED, 'renamed-status'] && $again->getValues() === [Link::ORDER_FAILED, 'renamed-status']
        && $rule->validate(['values'])
        ?: json_encode(['rule' => $rule->getValues(), 'again' => $again->getValues()]);
});

check('a mixed stale choice filters on the known status only', function() use ($makeRule, $ids, $fixtures) {
    $query = Order::find()->id($ids)->status(null);
    $makeRule([Link::ORDER_SKIPPED, 'drop table'])->modifyQuery($query);
    $got = array_map('intval', $query->ids());

    return $got === [(int)$fixtures[Link::ORDER_SKIPPED]->id] ?: json_encode($got);
});

check('“is one of” only unknown statuses matches no order (it does not widen to all)', function() use ($makeRule, $fixtures) {
    $rule = $makeRule(['bogus']);
    $query = Order::find()->status(null);
    $rule->modifyQuery($query);
    $matched = array_filter($fixtures, static fn(Order $o) => $rule->matchElement($o));

    return (int)$query->count() === 0 && $matched === [] && Order::find()->status(null)->count() > 0
        ?: json_encode(['count' => $query->count(), 'matched' => array_keys($matched)]);
});

check('“is not one of” only unknown statuses excludes nothing', function() use ($makeRule, $fixtures) {
    $rule = $makeRule(['bogus'], 'ni');
    $query = Order::find()->status(null);
    $rule->modifyQuery($query);
    $missed = array_filter($fixtures, static fn(Order $o) => !$rule->matchElement($o));
    $all = (int)Order::find()->status(null)->count();

    return (int)$query->count() === $all && $missed === [] ?: json_encode(['count' => $query->count(), 'all' => $all, 'missed' => array_keys($missed)]);
});

check('a stale rule saved and reloaded still matches nothing', function() use ($makeRule) {
    $again = Craft::$app->getConditions()->createConditionRule($makeRule(['bogus'])->getConfig());
    $query = Order::find()->status(null);
    $again->modifyQuery($query);

    return (int)$query->count() === 0 ?: 'widened to ' . $query->count();
});

check('an empty rule leaves the query alone', function() use ($makeRule, $ids, $fixtures) {
    $rule = $makeRule([]);
    $query = Order::find()->id($ids)->status(null);
    $rule->modifyQuery($query);
    $all = Order::find()->status(null);
    $rule->modifyQuery($all);

    return count($query->ids()) === count($ids) && (int)$all->count() === (int)Order::find()->status(null)->count()
        && $rule->matchElement($fixtures[Link::ORDER_NONE]) ?: 'narrowed';
});

// ---------------------------------------------------------------------------------------------
section('Orders index column');

check('“Zoho Books” is an available column on orders only', function() {
    $orders = Craft::$app->getElementSources()->getAvailableTableAttributes(Order::class);
    $entries = Craft::$app->getElementSources()->getAvailableTableAttributes(craft\elements\Entry::class);

    return isset($orders['zoStatus']) && !isset($entries['zoStatus']) ?: 'not registered as expected';
});

check('the cell shows the status to someone who can view syncs, and nothing to anyone else', function() use ($plugin, $fixtures) {
    $order = $fixtures[Link::ORDER_NOT_RECONCILED];
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $admin = $plugin->orderStatusHtml($order);
    Craft::$app->getUser()->setIdentity(null);
    $anonymous = $plugin->orderStatusHtml($order);

    return str_contains($admin, 'Not reconciled') && str_contains($admin, 'status orange') && $anonymous === ''
        ?: json_encode([$admin, $anonymous]);
});

check('over HTTP, the Orders index renders the column for each row', function() use ($ids, $fixtures) {
    $response = client('admin', 'claudepassword')('element-indexes/get-elements', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => ['zoStatus']],
        'criteria' => ['id' => array_values($ids), 'status' => null, 'isCompleted' => null],
    ], 'POST', true, true);
    $html = json_decode((string)$response->getBody(), true)['html'] ?? '';

    return $response->getStatusCode() === 200 && str_contains($html, 'Not reconciled') && str_contains($html, 'Failed')
        && str_contains($html, 'Not synced') && str_contains($html, 'Skipped')
        ?: $response->getStatusCode() . ': ' . substr(strip_tags((string)$response->getBody()), 0, 300);
});

// ---------------------------------------------------------------------------------------------
section('“Sync to Zoho Books” element action');

check('it queues every selected completed order, forced, and skips carts', function() use ($fixtures, $variant) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $cart = makeOrder($variant, false);
    $ids = [$fixtures[Link::ORDER_NONE]->id, $fixtures[Link::ORDER_FAILED]->id, $cart->id];
    $before = (new craft\db\Query())->from(craft\db\Table::QUEUE)->max('id');
    $action = new SyncToZoho();
    $ok = $action->performAction(Order::find()->id($ids)->status(null));
    $jobs = (new craft\db\Query())->select(['job'])->from(craft\db\Table::QUEUE)->where(['>', 'id', (int)$before])->column();
    $payloads = array_map(static fn($blob) => unserialize(is_resource($blob) ? stream_get_contents($blob) : $blob), $jobs);
    $orderIds = array_map(static fn($job) => $job->orderId ?? null, $payloads);
    $forced = array_filter($payloads, static fn($job) => ($job->force ?? false) === true);
    Craft::$app->getUser()->setIdentity(null);
    sort($orderIds);
    $want = [(int)$fixtures[Link::ORDER_NONE]->id, (int)$fixtures[Link::ORDER_FAILED]->id];
    sort($want);

    return $ok && $orderIds === $want && count($forced) === 2 && str_contains((string)$action->getMessage(), '1 incomplete')
        ?: json_encode(['ok' => $ok, 'orders' => $orderIds, 'message' => $action->getMessage()]);
});

check('it refuses someone without “Sync orders”, even if they reach it', function() use ($fixtures) {
    Craft::$app->getUser()->setIdentity(null);
    $action = new SyncToZoho();

    return $action->performAction(Order::find()->id($fixtures[Link::ORDER_NONE]->id)->status(null)) === false ?: 'ran';
});

check('it refuses when Zo is not connected', function() use ($fixtures, $settings) {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    $settings->organizationId = '';
    $connected = $settings->getIsConnected();
    $action = new SyncToZoho();
    $ok = $action->performAction(Order::find()->id($fixtures[Link::ORDER_NONE]->id)->status(null));
    $settings->organizationId = '10234695';
    Craft::$app->getUser()->setIdentity(null);

    return $connected || ($ok === false && str_contains((string)$action->getMessage(), 'not connected')) ?: (string)$action->getMessage();
});

[$viewer, $viewerPassword] = makeUser('orders', ['accesscp', 'accessplugin-commerce', 'commerce-manageorders', 'commerce-editorders', 'zo-viewsync']);

check('over HTTP, a user without “Sync orders” is not offered it and cannot run it', function() use ($viewer, $viewerPassword, $fixtures) {
    $response = client($viewer->username, $viewerPassword)('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => SyncToZoho::class,
        'elementIds' => [$fixtures[Link::ORDER_NONE]->id],
    ], 'POST', true, true);
    $data = json_decode((string)$response->getBody(), true);

    return $response->getStatusCode() >= 400 && empty($data['success']) ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 300);
});

check('over HTTP, an admin reaches the action (and is told Zo is not connected on this saved config)', function() use ($fixtures, $plugin) {
    $response = client('admin', 'claudepassword')('element-indexes/perform-action', [
        'elementType' => Order::class,
        'source' => '*',
        'context' => 'index',
        'viewState' => ['mode' => 'table', 'static' => false],
        'elementAction' => SyncToZoho::class,
        'elementIds' => [$fixtures[Link::ORDER_NONE]->id],
    ], 'POST', true, true);
    $body = (string)$response->getBody();

    return str_contains($body, 'not connected') || str_contains($body, 'queued for Zoho Books') ?: $response->getStatusCode() . ' ' . substr($body, 0, 300);
});

Craft::$app->getDb()->createCommand()->delete(craft\db\Table::QUEUE, ['like', 'description', 'Zoho Books', false])->execute();

// ---------------------------------------------------------------------------------------------
section('Deposit account per gateway');

check('a mapped gateway uses its own account, others the default, $ENV resolved', function() {
    putenv('ZO_STRIPE_CLEARING=acct-env-77');
    $_SERVER['ZO_STRIPE_CLEARING'] = 'acct-env-77';
    $s = new Settings(['depositAccountId' => 'acct-default', 'depositAccountMap' => [
        ['gateway' => 'stripe', 'accountId' => '$ZO_STRIPE_CLEARING'],
        ['gateway' => 'paypal', 'accountId' => 'acct-paypal'],
        ['gateway' => 'half-done', 'accountId' => ''],
    ]]);

    return $s->getDepositAccountFor('stripe') === 'acct-env-77'
        && $s->getDepositAccountFor('paypal') === 'acct-paypal'
        && $s->getDepositAccountFor('dummy') === 'acct-default'
        && $s->getDepositAccountFor(null) === 'acct-default'
        && !isset($s->depositAccountMap['half-done'])
        ?: json_encode($s->depositAccountMap);
});

check('the map is an attribute, so it persists (the private-property trap)', function() {
    $s = new Settings(['depositAccountMap' => [['gateway' => 'stripe', 'accountId' => 'a-1']]]);
    $array = $s->toArray();
    $again = new Settings(['depositAccountMap' => $array['depositAccountMap'] ?? null]);

    return ($array['depositAccountMap'] ?? null) === ['stripe' => 'a-1'] && $again->getDepositAccountMapRows() === [['gateway' => 'stripe', 'accountId' => 'a-1']]
        ?: json_encode($array['depositAccountMap'] ?? null);
});

// ---------------------------------------------------------------------------------------------
section('Processor fees read from the stored response');

$fee = fn(mixed $response, string $currency = 'USD') => $documents->feeFromResponse($response, $currency);

check('Stripe: an expanded charge’s balance transaction, in cents', fn() => $fee(json_encode(['object' => 'charge', 'balance_transaction' => ['fee' => 88, 'currency' => 'usd']])) === 0.88 ?: 'wrong');
check('Stripe: a payment intent’s expanded latest_charge', fn() => $fee(['object' => 'payment_intent', 'latest_charge' => ['balance_transaction' => ['fee' => 320, 'currency' => 'usd']]]) === 3.2 ?: 'wrong');
check('Stripe: the older charges.data list', fn() => $fee(['charges' => ['data' => [['balance_transaction' => ['fee' => 59, 'currency' => 'usd']]]]]) === 0.59 ?: 'wrong');
check('Stripe: a zero-decimal currency is not divided by 100', fn() => $fee(['balance_transaction' => ['fee' => 120, 'currency' => 'jpy']], 'JPY') === 120.0 ?: 'wrong');
check('Stripe: a fee settled in another currency is ignored, not converted', fn() => $fee(['balance_transaction' => ['fee' => 88, 'currency' => 'eur']]) === null ?: 'converted');
check('Stripe: an intent whose charge is a bare id has nothing to read', fn() => $fee(['object' => 'payment_intent', 'latest_charge' => 'ch_123']) === null ?: 'invented a fee');
check('PayPal Checkout: a capture inside purchase_units', fn() => $fee(['purchase_units' => [['payments' => ['captures' => [['seller_receivable_breakdown' => ['paypal_fee' => ['currency_code' => 'USD', 'value' => '1.17']]]]]]]]) === 1.17 ?: 'wrong');
check('PayPal Checkout: a capture response itself', fn() => $fee(['seller_receivable_breakdown' => ['paypal_fee' => ['currency_code' => 'USD', 'value' => '0.80']]]) === 0.8 ?: 'wrong');
check('PayPal Express (NVP)', fn() => $fee(['PAYMENTINFO_0_FEEAMT' => '0.89', 'PAYMENTINFO_0_CURRENCYCODE' => 'USD']) === 0.89 ?: 'wrong');
check('anything else is no fee', fn() => $fee('not json') === null && $fee(null) === null && $fee(['fee' => 'lots']) === null ?: 'invented a fee');

$order = makeOrder($variant);
$total = Money::round($order->getTotalPrice());

check('a “fee” as large as the payment is a misread, not a fee', function() use ($documents, $order, $total) {
    $t = new Transaction(['amount' => $total, 'currency' => 'USD', 'response' => ['balance_transaction' => ['fee' => (int)round($total * 100), 'currency' => 'usd']]]);

    return $documents->processorFee($t) === null ?: 'accepted';
});

check('EVENT_DEFINE_PROCESSOR_FEE can supply a fee Zo cannot read, or veto one it can', function() use ($documents, $total) {
    $supply = fn(ProcessorFeeEvent $e) => $e->fee = 0.42;
    $documents->on(Documents::EVENT_DEFINE_PROCESSOR_FEE, $supply);
    $supplied = $documents->processorFee(new Transaction(['amount' => $total, 'currency' => 'USD', 'response' => ['object' => 'payment_intent', 'latest_charge' => 'ch_1']]));
    $documents->off(Documents::EVENT_DEFINE_PROCESSOR_FEE, $supply);

    $veto = fn(ProcessorFeeEvent $e) => $e->fee = null;
    $documents->on(Documents::EVENT_DEFINE_PROCESSOR_FEE, $veto);
    $vetoed = $documents->processorFee(new Transaction(['amount' => $total, 'currency' => 'USD', 'response' => ['balance_transaction' => ['fee' => 88, 'currency' => 'usd']]]));
    $documents->off(Documents::EVENT_DEFINE_PROCESSOR_FEE, $veto);

    return $supplied === 0.42 && $vetoed === null ?: json_encode([$supplied, $vetoed]);
});

// ---------------------------------------------------------------------------------------------
section('Payments and refunds against the synced invoice');

$stripeLike = json_encode(['object' => 'charge', 'balance_transaction' => ['fee' => 88, 'currency' => 'usd']]);

check('fees off: the payment goes to the gateway’s account with no bank charges', function() use ($documents, $settings, $order, $total, $stripeLike) {
    $settings->setAttributes(['recordProcessorFees' => false, 'depositAccountId' => 'acct-default', 'depositAccountMap' => ['dummy' => 'acct-dummy-clearing']], false);
    $t = addTransaction($order, TransactionRecord::TYPE_PURCHASE, $total, $stripeLike);
    $payload = $documents->buildPaymentPayload($t, 'c-1', 'i-1', $total);

    return ($payload['account_id'] ?? null) === 'acct-dummy-clearing' && !isset($payload['bank_charges']) ?: json_encode($payload);
});

check('a full sync records the payment once, with the fee as bank charges, in the gateway’s account', function() use ($plugin, $settings, $variant, $stripeLike) {
    $settings->setAttributes(['recordProcessorFees' => true, 'syncPayments' => true, 'syncRefunds' => false], false);
    $order = makeOrder($variant);
    $total = Money::round($order->getTotalPrice());
    addTransaction($order, TransactionRecord::TYPE_PURCHASE, $total, $stripeLike);
    $order = reloadOrder($order);

    $plugin->getAuth()->forgetAccessToken();
    mockZoho([
        tokenResponse(),
        zohoOk(['contact' => ['contact_id' => 'c-20']]),
        zohoOk(['invoice' => ['invoice_id' => 'i-20', 'invoice_number' => 'INV-20', 'total' => $total]]),
        zohoOk([], 200),
        zohoOk(['payment' => ['payment_id' => 'p-20', 'payment_number' => '20', 'amount' => $total]]),
    ]);
    $result = $plugin->getSync()->syncOrder($order);
    $body = sentBody(requestIndex('customerpayments'));
    $first = $result->getIsSuccessful() && ($body['account_id'] ?? null) === 'acct-dummy-clearing' && ($body['bank_charges'] ?? null) === 0.88
        && Money::equal((float)($body['amount'] ?? 0), $total) && ($body['invoices'][0]['invoice_id'] ?? null) === 'i-20';

    // Again: everything is reused, and no second payment is sent for the same transaction.
    mockZoho([]);
    $again = $plugin->getSync()->syncOrder(reloadOrder($order));
    $resent = requestIndex('customerpayments') !== null;

    return $first && !$resent && $plugin->getLinks()->orderStatus((int)$order->id) === Link::ORDER_SYNCED
        ?: json_encode(['summary' => $result->getSummary(), 'body' => $body, 'resent' => $resent, 'again' => $again->getSummary()]);
});

check('a refund’s cash leaves the gateway’s own account', function() use ($plugin, $settings, $variant) {
    $settings->setAttributes(['syncPayments' => false, 'syncRefunds' => true], false);
    $order = makeOrder($variant);
    $total = Money::round($order->getTotalPrice());
    addTransaction($order, TransactionRecord::TYPE_REFUND, -5.00);
    $order = reloadOrder($order);

    $plugin->getAuth()->forgetAccessToken();
    mockZoho([
        tokenResponse(),
        zohoOk(['contact' => ['contact_id' => 'c-21']]),
        zohoOk(['invoice' => ['invoice_id' => 'i-21', 'total' => $total]]),
        zohoOk([], 200),
        zohoOk(['creditnote' => ['creditnote_id' => 'cn-21', 'creditnote_number' => 'CN-21', 'total' => 5.00]]),
        zohoOk([], 200),
        zohoOk(['creditnote_refund' => ['creditnote_refund_id' => 'r-21']]),
    ]);
    $plugin->getSync()->syncOrder($order);
    $body = sentBody(requestIndex('/refunds'));

    return ($body['from_account_id'] ?? null) === 'acct-dummy-clearing' ?: json_encode([sentRequests(), $body]);
});

finish();
