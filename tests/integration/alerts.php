<?php
/**
 * Failure alerts — Zo's port of the connector-family pattern (reference: craft-erpy 0f8ea45).
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-zo/tests/integration/alerts.php
 *
 * Drives the real latch, the real mailer and the real webhook code: sync failures, unreconciled
 * documents, a refused refresh token and a 401 that refreshing does not fix each open exactly one
 * incident, send exactly one alert, and send exactly one recovery. Zoho and the webhook receiver
 * are Guzzle MockHandlers (the harness has no outbound network); the webhook still passes the
 * SSRF guard for real. Settings stay in memory.
 */

require __DIR__ . '/_support.php';

use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\web\View;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\events\AlertEvent;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\models\Settings;
use justinholtweb\zo\services\Alerts;
use justinholtweb\zo\widgets\HealthWidget;

$alerts = $plugin->getAlerts();
$links = $plugin->getLinks();

// The webhook receiver.
$history = [];
$hookMock = new MockHandler();
$hookStack = HandlerStack::create($hookMock);
$hookStack->push(Middleware::history($history));
$alerts->webhookClient = new Client(['handler' => $hookStack]);

$reset = function(array $overrides = []) use ($settings, &$mail, &$history, $hookMock) {
    $settings->setAttributes(array_merge([
        'alertRecipients' => 'ops@example.test, books@example.test',
        'alertWebhookUrl' => '',
        'alertWebhookSecret' => '',
        'alertWebhookFormat' => 'slack',
        'alertOnFailures' => true,
        'alertFailureThreshold' => 2,
        'alertWindowMinutes' => 60,
        'alertOnVariance' => true,
        'alertOnAuthFailure' => true,
        'alertCooldownMinutes' => 0,
        'allowPrivateAlertWebhookHosts' => false,
        'varianceTolerance' => 0.01,
    ], $overrides), false);
    $mail = [];
    $history = [];
    $hookMock->reset();
};

$latch = fn(string $incident) => (new Query())->from(Table::ALERTS)->where(['incident' => $incident])->one() ?: [];
$ago = fn(string $modify) => Db::prepareDateForDb((new DateTime())->modify($modify));
$keyPrefix = "zo-alerts-$suffix:";
$cleanup['linkKeys'][] = $keyPrefix;
$n = 0;

// A failed link, as a sync would leave one.
$fail = function(string $type = Link::TYPE_INVOICE, string $error = 'Zoho Books: Invalid value passed for customer_id') use ($links, $keyPrefix, &$n) {
    $link = $links->claim($type, $keyPrefix . (++$n));
    $links->markFailed($link, $error);

    return $link;
};
$age = function(string $modify, string $column = 'dateUpdated') use ($keyPrefix) {
    Craft::$app->getDb()->createCommand()->update(Table::LINKS, [$column => Db::prepareDateForDb((new DateTime())->modify($modify))], ['like', 'craftKey', $keyPrefix . '%', false])->execute();
};

Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();

// ---------------------------------------------------------------------------------------------
section('Settings');

check('a fresh install saves with no recipients and no webhook (nothing is required)', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => '', 'alertWebhookUrl' => '']));

    return $s->validate() ?: json_encode($s->getErrors());
});

check('a bad address is refused, and named', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => 'ops@example.test, not-an-address']));

    return !$s->validate() && str_contains(implode(' ', $s->getErrors('alertRecipients')), 'not-an-address') ?: json_encode($s->getErrors());
});

check('an unset $ENV reference is allowed and means nobody', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertRecipients' => '$ZO_ALERTS_NOT_SET', 'alertWebhookUrl' => '$ZO_HOOK_NOT_SET']));

    return $s->validate() && $s->recipientList() === [] ?: json_encode($s->getErrors());
});

check('a non-http webhook URL is refused at save', function() use ($originalSettings) {
    $s = new Settings(array_merge($originalSettings, ['alertWebhookUrl' => 'ftp://hooks.example.test/x']));

    return !$s->validate() && $s->hasErrors('alertWebhookUrl') ?: 'accepted';
});

check('recipients split on commas, semicolons and newlines, de-duplicated', function() {
    $s = new Settings(['alertRecipients' => "a@example.test; b@example.test\nc@example.test, a@example.test"]);

    return $s->recipientList() === ['a@example.test', 'b@example.test', 'c@example.test'] ?: json_encode($s->recipientList());
});

check('the alert settings are attributes, so they persist', function() use ($settings) {
    $missing = array_diff(['alertRecipients', 'alertWebhookUrl', 'alertWebhookFormat', 'alertWebhookSecret', 'alertOnFailures', 'alertFailureThreshold', 'alertWindowMinutes', 'alertOnVariance', 'alertOnAuthFailure', 'alertCooldownMinutes'], array_keys($settings->toArray()));

    return $missing === [] ?: implode(', ', $missing);
});

// ---------------------------------------------------------------------------------------------
section('Not connected');

check('an install with no credentials checks nothing and records nothing', function() use ($alerts, $settings) {
    $settings->setAttributes(['clientId' => '', 'clientSecret' => '', 'refreshToken' => ''], false);
    $hasConnection = $settings->getHasCredentials();

    return $hasConnection || ($alerts->check() === [] && !(new Query())->from(Table::ALERTS)->exists()) ?: 'checked anyway';
});

connectFixture();
$reset();

// ---------------------------------------------------------------------------------------------
section('The SSRF guard on the webhook');

foreach ([
    'http://127.0.0.1/hook' => 'loopback',
    'http://169.254.169.254/latest/meta-data/' => 'the cloud metadata service',
    'http://10.1.2.3/hook' => 'a private address',
    'http://[::1]/hook' => 'IPv6 loopback',
    'http://[::ffff:127.0.0.1]/hook' => 'IPv4-mapped loopback',
    'http://100.64.0.1/hook' => 'carrier-grade NAT',
    'ftp://93.184.215.14/hook' => 'a non-http scheme',
    'https://user:pass@93.184.215.14/hook' => 'credentials in the URL',
] as $url => $what) {
    check("refuses $what", function() use ($alerts, $url) {
        return is_string($alerts->webhookTarget($url)) ?: 'allowed';
    });
}

check('a public address is allowed, and pinned', function() use ($alerts) {
    $t = $alerts->webhookTarget('https://93.184.215.14/hook');

    return is_array($t) && $t['addresses'] === ['93.184.215.14'] && $t['port'] === 443 ?: json_encode($t);
});

check('a refused URL is never requested', function() use ($alerts, &$history) {
    $result = $alerts->postWebhook('http://127.0.0.1:8080/hook', ['text' => 'x']);

    return is_string($result) && $history === [] ?: 'requested: ' . count($history);
});

check('the send pins the address, refuses redirects and does not throw on a 4xx', function() use ($alerts, $hookMock, &$history) {
    $hookMock->append(new Psr7Response(404));
    $result = $alerts->postWebhook('https://93.184.215.14/hook', ['text' => 'x']);
    $options = $history[0]['options'] ?? [];
    $pin = $options['curl'][CURLOPT_RESOLVE][0] ?? '';

    return $result === 'HTTP 404' && $pin === '93.184.215.14:443:93.184.215.14' && ($options['allow_redirects'] ?? null) === false
        ?: json_encode(['result' => $result, 'pin' => $pin]);
});

check('allowPrivateAlertWebhookHosts lets a LAN host through, unpinned', function() use ($alerts, $settings) {
    $settings->allowPrivateAlertWebhookHosts = true;
    $t = $alerts->webhookTarget('http://10.1.2.3/hook');
    $settings->allowPrivateAlertWebhookHosts = false;

    return is_array($t) && $t['addresses'] === [] ?: json_encode($t);
});

// ---------------------------------------------------------------------------------------------
section('Redaction');

check('the client secret and refresh token are taken out by value', function() use ($alerts) {
    $out = $alerts->redact('Zoho said: bad secret fixture-secret-abcdef for token fixture-refresh-123456');

    return !str_contains($out, 'fixture-secret-abcdef') && !str_contains($out, 'fixture-refresh-123456') ?: $out;
});

check('anything shaped like a credential is taken out by pattern', function() use ($alerts) {
    $out = $alerts->redact('Authorization: Zoho-oauthtoken 1000.abcdef123456.xyz {"client_secret":"hunter22xyz"} refresh_token=1000.aaaabbbb&x=1');

    return !str_contains($out, '1000.abcdef123456') && !str_contains($out, 'hunter22xyz') && !str_contains($out, '1000.aaaabbbb') ?: $out;
});

check('Zoho’s own error codes survive redaction', function() use ($alerts) {
    $out = $alerts->redact('{"code":1001,"message":"Contact already exists"}');

    return str_contains($out, '1001') ?: $out;
});

check('tags are stripped and the length is capped', function() use ($alerts) {
    $out = $alerts->redact('<b>' . str_repeat('x', 900) . '</b>');

    return !str_contains($out, '<b>') && mb_strlen($out) === 500 ?: mb_strlen($out) . ' chars';
});

// ---------------------------------------------------------------------------------------------
section('Orders failing to sync');

check('below the threshold, nothing opens and nothing is sent', function() use ($alerts, $fail, $latch, &$mail) {
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: json_encode($latch(Alerts::INCIDENT_FAILURES));
});

check('reaching it opens the incident and sends one email to every recipient', function() use ($alerts, $fail, $latch, &$mail) {
    $fail(Link::TYPE_PAYMENT, 'Zoho Books: The amount entered is more than the balance due');
    $results = $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && count($mail) === 1
        && $mail[0]['to'] === ['ops@example.test', 'books@example.test'] && ($results[0]['transition'] ?? null) === 'opened'
        ?: json_encode(['mail' => count($mail), 'results' => $results]);
});

check('the email says what failed and links the Sync screen filtered to failures', function() use (&$mail) {
    $body = $mail[0]['body'] ?? '';

    return str_contains($mail[0]['subject'] ?? '', 'Orders failing to sync')
        && str_contains($body, 'more than the balance due')
        && str_contains($body, 'zo/sync') && str_contains($body, 'status=failed')
        ?: substr($body, 0, 600);
});

check('it stays quiet while open, however often it is checked', function() use ($alerts, $fail, &$mail) {
    $fail();
    $alerts->check();
    $alerts->check();

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('a sync that fails evaluates alerts by itself — no cron', function() use ($plugin, $alerts, $latch, &$mail, $reset) {
    $reset(['alertFailureThreshold' => 1, 'reuseContactByEmail' => false]);
    Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();
    Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-1 day'))], ['status' => Link::STATUS_FAILED])->execute();

    $order = makeOrder(makeVariant());
    mockZoho([tokenResponse(), zohoError(400, 4, 'Invalid value passed for contact_name')]);
    $result = $plugin->getSync()->syncOrder(reloadOrder($order));

    return !$result->getIsSuccessful() && ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($mail[0]['body'], 'Invalid value passed for contact_name')
        ?: json_encode(['summary' => $result->getSummary(), 'latch' => $latch(Alerts::INCIDENT_FAILURES), 'mail' => count($mail)]);
});

check('a whole quiet window recovers it, with one recovery that says what is still failed', function() use ($alerts, $latch, &$mail) {
    $mail = [];
    Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-2 hours'))], ['status' => Link::STATUS_FAILED])->execute();
    $results = $alerts->check([Alerts::INCIDENT_FAILURES]);
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $body = $mail[0]['body'] ?? '';

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && count($mail) === 1
        && str_contains($mail[0]['subject'], 'Recovered') && str_contains($body, 'still show as failed')
        && ($results[0]['transition'] ?? null) === 'recovered'
        ?: json_encode(['mail' => count($mail), 'body' => $body]);
});

check('a reopening inside the quiet period is held, then sent once it ends', function() use ($alerts, $fail, $latch, $settings, &$mail) {
    $mail = [];
    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('+30 minutes'))], ['incident' => Alerts::INCIDENT_FAILURES])->execute();
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $held = ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'open' && $mail === [];

    Craft::$app->getDb()->createCommand()->update(Table::ALERTS, ['quietUntil' => Db::prepareDateForDb((new DateTime())->modify('-1 minute'))], ['incident' => Alerts::INCIDENT_FAILURES])->execute();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $held && count($mail) === 1 ?: json_encode(['held' => $held, 'mail' => count($mail)]);
});

check('a failed send is released and retried on the next check, not lost', function() use ($alerts, $latch, &$mail, &$mailFails) {
    $mail = [];
    Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-2 hours'))], ['status' => Link::STATUS_FAILED])->execute();
    $mailFails = true;
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $mailFails = false;
    $owed = array_key_exists('recoveryNotifiedAt', $latch(Alerts::INCIDENT_FAILURES)) && $latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] === null;
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $owed && count($mail) === 1 && ($latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] ?? null) !== null
        ?: json_encode(['owed' => $owed, 'mail' => count($mail)]);
});

check('an item failing is best-effort and never alerts', function() use ($alerts, $fail, $latch, &$mail, $reset) {
    $reset(['alertFailureThreshold' => 1]);
    $fail(Link::TYPE_ITEM);
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: 'an item failure alerted';
});

check('switched off, failures alert nobody', function() use ($alerts, $fail, $latch, &$mail, $reset) {
    $reset(['alertOnFailures' => false, 'alertFailureThreshold' => 1]);
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return ($latch(Alerts::INCIDENT_FAILURES)['state'] ?? null) === 'ok' && $mail === [] ?: 'alerted';
});

Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-1 day'))], ['status' => Link::STATUS_FAILED])->execute();

// ---------------------------------------------------------------------------------------------
section('Documents not reconciling');

$reset();

check('a document synced inside the window that does not reconcile opens it', function() use ($alerts, $links, $keyPrefix, $latch, &$mail) {
    $link = $links->claim(Link::TYPE_INVOICE, $keyPrefix . 'variance');
    $links->markSynced($link, 'inv-v1', 'INV-00042', 100.00, 100.37);
    $alerts->check([Alerts::INCIDENT_VARIANCE]);
    $body = $mail[0]['body'] ?? '';

    return ($latch(Alerts::INCIDENT_VARIANCE)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($body, 'INV-00042') && str_contains($body, '100.37') && str_contains($body, 'status=variance')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_VARIANCE), 'body' => $body]);
});

check('a difference inside the tolerance does not', function() use ($alerts, $links, $keyPrefix, $latch, &$mail, $reset) {
    $reset();
    Craft::$app->getDb()->createCommand()->delete(Table::ALERTS, ['incident' => Alerts::INCIDENT_VARIANCE])->execute();
    Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateSynced' => Db::prepareDateForDb((new DateTime())->modify('-1 day'))], ['like', 'craftKey', $keyPrefix . '%', false])->execute();
    $link = $links->claim(Link::TYPE_INVOICE, $keyPrefix . 'tolerated');
    $links->markSynced($link, 'inv-v2', 'INV-00043', 100.00, 100.004);
    $alerts->check([Alerts::INCIDENT_VARIANCE]);

    return ($latch(Alerts::INCIDENT_VARIANCE)['state'] ?? null) === 'ok' && $mail === [] ?: 'alerted on a rounding hair';
});

check('a quiet window recovers it, and the recovery counts what still does not reconcile', function() use ($alerts, $links, $keyPrefix, $latch, &$mail, $reset) {
    $link = $links->claim(Link::TYPE_INVOICE, $keyPrefix . 'variance2');
    $links->markSynced($link, 'inv-v3', 'INV-00044', 50.00, 49.00);
    $alerts->check([Alerts::INCIDENT_VARIANCE]);
    $mail = [];
    Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateSynced' => Db::prepareDateForDb((new DateTime())->modify('-2 hours'))], ['like', 'craftKey', $keyPrefix . '%', false])->execute();
    $alerts->check([Alerts::INCIDENT_VARIANCE]);
    $body = $mail[0]['body'] ?? '';

    return ($latch(Alerts::INCIDENT_VARIANCE)['state'] ?? null) === 'ok' && count($mail) === 1 && str_contains($body, 'still do not reconcile')
        ?: json_encode(['mail' => count($mail), 'body' => $body]);
});

// ---------------------------------------------------------------------------------------------
section('Zoho refusing the connection');

$reset();

check('a 401 that a fresh token does not fix opens it and alerts at once', function() use ($plugin, $latch, &$mail) {
    $plugin->getAuth()->forgetAccessToken();
    mockZoho([
        tokenResponse('t-1'),
        zohoError(401, 57, 'You are not authorized to perform this operation'),
        tokenResponse('t-2'),
        zohoError(401, 57, 'You are not authorized to perform this operation'),
    ]);

    try {
        $plugin->getApi()->get('organizations');
    } catch (Throwable) {
    }

    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'open' && count($mail) === 1
        && str_contains($mail[0]['body'], 'not authorized') && str_contains($mail[0]['body'], 'settings/plugins/zo')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => count($mail)]);
});

check('a 401 that the refresh does fix is not an incident', function() use ($plugin, $latch) {
    $before = $latch(Alerts::INCIDENT_AUTH)['signalledAt'] ?? null;
    $plugin->getAuth()->forgetAccessToken();
    mockZoho([tokenResponse('t-3'), zohoError(401, 57, 'expired'), tokenResponse('t-4'), zohoOk(['organizations' => []], 200)]);
    $plugin->getApi()->get('organizations');

    // That success also clears the open incident: the next check sees the signal cleared.
    return ($latch(Alerts::INCIDENT_AUTH)['signalledAt'] ?? null) === $before ?: 'a recovered 401 was signalled';
});

check('the next authenticated success recovered it, with one recovery', function() use ($latch, &$mail) {
    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'ok' && count($mail) === 2 && str_contains($mail[1]['subject'], 'Recovered')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => array_column($mail, 'subject')]);
});

check('a refused refresh token opens it too', function() use ($plugin, $latch, &$mail, $reset) {
    $reset();
    $plugin->getAuth()->forgetAccessToken();
    mockZoho([new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))]);

    try {
        $plugin->getApi()->get('organizations');
    } catch (Throwable) {
    }

    return ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'open' && count($mail) === 1 && str_contains($mail[0]['body'], 'reconnect')
        ?: json_encode(['latch' => $latch(Alerts::INCIDENT_AUTH), 'mail' => count($mail)]);
});

check('a second refusal does not send a second alert', function() use ($plugin, &$mail) {
    $plugin->getAuth()->forgetAccessToken();
    mockZoho([new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))]);

    try {
        $plugin->getApi()->get('organizations');
    } catch (Throwable) {
    }

    return count($mail) === 1 ?: count($mail) . ' emails';
});

check('a network failure on refresh is not an authentication failure', function() use ($plugin, $latch, $reset) {
    $reset();
    Craft::$app->getDb()->createCommand()->delete(Table::ALERTS, ['incident' => Alerts::INCIDENT_AUTH])->execute();
    $plugin->getAuth()->forgetAccessToken();
    mockZoho([new ConnectException('cURL error 6: Could not resolve host', new Psr7Request('POST', 'https://accounts.zoho.com/oauth/v2/token'))]);

    try {
        $plugin->getApi()->get('organizations');
    } catch (Throwable) {
    }

    return empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) ?: 'the network was taken for a refusal';
});

check('a 500 is not an authentication failure', function() use ($plugin, $latch) {
    $plugin->getAuth()->forgetAccessToken();
    mockZoho([tokenResponse(), zohoError(500, 1, 'Internal error')]);

    try {
        $plugin->getApi()->get('organizations');
    } catch (Throwable) {
    }

    return empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) ?: 'a 500 was taken for a refusal';
});

check('switched off, a refusal records the signal but alerts nobody', function() use ($plugin, $latch, &$mail, $reset) {
    $reset(['alertOnAuthFailure' => false]);
    $plugin->getAuth()->forgetAccessToken();
    mockZoho([new Psr7Response(200, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))]);

    try {
        $plugin->getApi()->get('organizations');
    } catch (Throwable) {
    }

    return !empty($latch(Alerts::INCIDENT_AUTH)['signalledAt']) && ($latch(Alerts::INCIDENT_AUTH)['state'] ?? null) === 'ok' && $mail === []
        ?: json_encode($latch(Alerts::INCIDENT_AUTH));
});

$plugin->getAuth()->forgetAccessToken();
Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();

// ---------------------------------------------------------------------------------------------
section('The webhook');

$reset(['alertRecipients' => '', 'alertWebhookUrl' => 'https://93.184.215.14/services/T000/B000/xyz', 'alertWebhookSecret' => "whsec-$suffix", 'alertFailureThreshold' => 1]);

check('an incident posts a Slack message, signed, to the pinned address', function() use ($alerts, $fail, $hookMock, &$history, $suffix) {
    $hookMock->append(new Psr7Response(200));
    $fail();
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $request = $history[0]['request'] ?? null;

    if (!$request) {
        return 'nothing posted';
    }

    $body = (string)$request->getBody();
    $payload = json_decode($body, true);
    $expected = 'sha256=' . hash_hmac('sha256', $request->getHeaderLine('X-Zo-Timestamp') . '.' . $body, "whsec-$suffix");

    return str_contains($payload['text'] ?? '', 'Orders failing to sync') && isset($payload['blocks'])
        && hash_equals($expected, $request->getHeaderLine('X-Zo-Signature'))
        && ($history[0]['options']['curl'][CURLOPT_RESOLVE][0] ?? '') === '93.184.215.14:443:93.184.215.14'
        ?: $body;
});

check('a webhook that fails with no email configured is retried, not lost', function() use ($alerts, $hookMock, $latch, &$history) {
    Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-2 hours'))], ['status' => Link::STATUS_FAILED])->execute();
    $hookMock->append(new Psr7Response(500));
    $alerts->check([Alerts::INCIDENT_FAILURES]);
    $owed = array_key_exists('recoveryNotifiedAt', $latch(Alerts::INCIDENT_FAILURES)) && $latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] === null;
    $hookMock->append(new Psr7Response(200));
    $alerts->check([Alerts::INCIDENT_FAILURES]);

    return $owed && ($latch(Alerts::INCIDENT_FAILURES)['recoveryNotifiedAt'] ?? null) !== null && count($history) === 3
        ?: json_encode(['owed' => $owed, 'posts' => count($history)]);
});

check('Teams gets an Adaptive Card, JSON gets a flat zo.alert event', function() use ($alerts) {
    $m = $alerts->compose(Alerts::INCIDENT_AUTH, false, 'HTTP 401');
    $teams = $alerts->payload('teams', $m);
    $json = $alerts->payload('json', $m);

    return ($teams['attachments'][0]['content']['type'] ?? null) === 'AdaptiveCard'
        && $json['event'] === 'zo.alert.opened' && $json['incident'] === 'auth' && str_contains($json['syncUrl'], 'zo/sync')
        ?: json_encode([$teams, $json]);
});

check('a handler on EVENT_BEFORE_NOTIFY can reword or swallow an alert', function() use ($alerts) {
    $seen = null;
    $handler = function(AlertEvent $e) use (&$seen) {
        $seen = $e->subject;
        $e->isValid = false;
    };
    $alerts->on(Alerts::EVENT_BEFORE_NOTIFY, $handler);
    $result = $alerts->notify('test', false, 'x');
    $alerts->off(Alerts::EVENT_BEFORE_NOTIFY, $handler);

    return $result === true && is_string($seen) ?: 'not called';
});

Craft::$app->getDb()->createCommand()->update(Table::LINKS, ['dateUpdated' => Db::prepareDateForDb((new DateTime())->modify('-1 day'))], ['status' => Link::STATUS_FAILED])->execute();

// ---------------------------------------------------------------------------------------------
section('Dashboard widget');

$reset();

check('it shows the connection, the counts and any open incident', function() {
    Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    Craft::$app->getDb()->createCommand()->upsert(Table::ALERTS, [
        'incident' => Alerts::INCIDENT_AUTH, 'state' => 'open', 'detail' => 'shown on hover',
        'dateCreated' => Db::prepareDateForDb(new DateTime()), 'dateUpdated' => Db::prepareDateForDb(new DateTime()), 'uid' => craft\helpers\StringHelper::UUID(),
    ], ['state' => 'open', 'detail' => 'shown on hover'])->execute();
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);
    $html = (string)(new HealthWidget())->getBodyHtml();
    $view->setTemplateMode($mode);

    return str_contains($html, 'Zoho refused the connection') && str_contains($html, 'shown on hover')
        && str_contains($html, 'Not reconciled') && str_contains($html, 'status=failed') && HealthWidget::isSelectable()
        ?: substr(strip_tags($html), 0, 400);
});

check('it is registered with the Dashboard', function() {
    return in_array(HealthWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes(), true) ?: 'missing';
});

Craft::$app->getUser()->setIdentity(null);
Craft::$app->getDb()->createCommand()->delete(Table::ALERTS)->execute();

// ---------------------------------------------------------------------------------------------
section('Console');

check('zo/alerts/check runs and exits 0', function() {
    exec('php craft zo/alerts/check 2>&1', $out, $code);

    return $code === 0 && str_contains(implode("\n", $out), 'ot connected') || $code === 0 && str_contains(implode("\n", $out), 'open incident')
        ?: "exit $code: " . implode(' | ', $out);
});

check('zo/alerts/test refuses with nothing configured (saved settings)', function() {
    exec('php craft zo/alerts/test 2>&1', $out, $code);

    return $code === 78 && str_contains(implode("\n", $out), 'nothing to send to') ?: "exit $code: " . implode(' | ', $out);
});

// ---------------------------------------------------------------------------------------------
section('“Send a test alert” over HTTP');

[$viewer, $password] = makeUser('alerts', ['accesscp', 'accessplugin-zo', 'zo-viewsync', 'zo-syncorders', 'zo-managelinks', 'zo-viewlog']);

check('anonymous is refused', function() {
    $status = client(null, null)('zo/alerts/test')->getStatusCode();

    return in_array($status, [400, 401, 403], true) ?: "status $status";
});

check('a non-admin with every Zo permission is refused', function() use ($viewer, $password) {
    $status = client($viewer->username, $password)('zo/alerts/test')->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('an admin without a CSRF token is refused', function() {
    $status = client('admin', 'claudepassword')('zo/alerts/test', [], 'POST', false)->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('an admin GET is refused', function() {
    $status = client('admin', 'claudepassword')('zo/alerts/test', [], 'GET')->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('an admin POST answers JSON from the saved settings (and takes no URL from the request)', function() {
    $response = client('admin', 'claudepassword')('zo/alerts/test', ['alertWebhookUrl' => 'http://169.254.169.254/']);
    $data = json_decode((string)$response->getBody(), true);

    return in_array($response->getStatusCode(), [200, 400], true) && is_string($data['message'] ?? null) && !str_contains((string)$data['message'], '169.254')
        ?: $response->getStatusCode() . ' ' . substr((string)$response->getBody(), 0, 200);
});

check('the settings screen’s test button is bound to an id that exists', function() {
    $twig = (string)file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings.twig');

    return str_contains($twig, 'id="zo-test-alert"') && str_contains($twig, "bind('zo-test-alert', 'POST', 'zo/alerts/test'") ?: 'unbound';
});

finish();
