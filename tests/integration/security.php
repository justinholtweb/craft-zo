<?php
/**
 * Where the Zoho refresh token lives, who may connect, and who may read an order's payload or wipe
 * the log — checked in the plugin-testing harness, mostly over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-zo/tests/integration/security.php
 *
 * Until 5.0.1 the OAuth callback saved the refresh token — read and write access to the merchant's
 * books — as a plugin setting, so it was committed with project config, and connecting refused to
 * run where admin changes were off. "View what has been synced" could read any order's payload, and
 * "View the log" could clear it.
 *
 * Zoho is played by two throwaway templates: the accounts server's token endpoint and the Books
 * organizations endpoint, reached through Zo's own `accountsUrl` / `apiBaseUrl` overrides. The whole
 * connect round trip runs with CRAFT_ALLOW_ADMIN_CHANGES flipped off in the harness .env, which is
 * always put back. Self-cleaning: settings, the connection row and the templates are restored.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\zo\db\Table;
use justinholtweb\zo\helpers\Secret;
use justinholtweb\zo\migrations\m261004_000000_connection_table;
use justinholtweb\zo\models\Settings;
use justinholtweb\zo\Plugin;
use justinholtweb\zo\services\Auth;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$zo = Plugin::getInstance();
$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'Zo-' . bin2hex(random_bytes(6));
$db = Craft::$app->getDb();
$envFile = $root . '/.env';
$envBefore = (string)file_get_contents($envFile);
$settingsBefore = Craft::$app->getProjectConfig()->get('plugins.zo.settings');
$rowsBefore = (new Query())->from(Table::CONNECTION)->all();
$fake = $root . "/templates/zo-security-$run";
$cleanup = ['users' => []];

$restoreEnv = static function() use ($envFile, $envBefore) {
    if ((string)file_get_contents($envFile) !== $envBefore) {
        file_put_contents($envFile, $envBefore);
    }
};

$reloadConfig = static function() {
    Craft::$app->getInfo()->configVersion = (string)(new Query())->select('configVersion')->from('{{%info}}')->scalar();
    Craft::$app->getProjectConfig()->reset();
};

$storeSettings = static function(array $settings, string $why) use ($reloadConfig) {
    $reloadConfig();
    $pc = Craft::$app->getProjectConfig();
    $pc->set('plugins.zo.settings', $settings, $why);
    $pc->saveModifiedConfigData();
    $pc->writeYamlFiles(true);
    Plugin::getInstance()->getSettings()->setAttributes(ProjectConfigHelper::unpackAssociativeArray($settings), false);
};

$stored = static function(string $key) use ($reloadConfig) {
    $reloadConfig();

    return Craft::$app->getProjectConfig()->get("plugins.zo.settings.$key");
};

$connection = static function(): ?array {
    Plugin::getInstance()->getConnection()->reset();

    return Plugin::getInstance()->getConnection()->get();
};

register_shutdown_function(function() use (&$cleanup, $db, $rowsBefore, $settingsBefore, $storeSettings, $reloadConfig, $restoreEnv, $fake) {
    $restoreEnv();
    if (is_dir($fake)) {
        craft\helpers\FileHelper::removeDirectory($fake);
    }
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    $db->createCommand()->delete(Table::CONNECTION)->execute();
    foreach ($rowsBefore as $row) {
        $db->createCommand()->insert(Table::CONNECTION, $row)->execute();
    }
    $reloadConfig();
    if (Craft::$app->getProjectConfig()->get('plugins.zo.settings') !== $settingsBefore) {
        $storeSettings($settingsBefore, 'Restore after security.php');
    }
});

$withAdminChangesOff = static function(callable $fn) use ($envFile, $envBefore, $restoreEnv) {
    $off = preg_match('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', $envBefore)
        ? preg_replace('/^CRAFT_ALLOW_ADMIN_CHANGES=.*$/m', 'CRAFT_ALLOW_ADMIN_CHANGES=false', $envBefore)
        : rtrim($envBefore) . "\nCRAFT_ALLOW_ADMIN_CHANGES=false\n";
    file_put_contents($envFile, $off);
    try {
        return $fn();
    } finally {
        $restoreEnv();
    }
};

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $json = ['Accept' => 'application/json'];
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => $json])->getBody(), true)['csrfTokenValue'] ?? '');
    $login = $http->post('index.php?p=actions/users/login', ['headers' => $json, 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

    if ($login->getStatusCode() !== 200) {
        echo "Could not sign in as $username\n";
        exit(1);
    }

    $post = static fn(string $action, array $params) => $http->post("index.php?p=admin/actions/$action", [
        'headers' => $json,
        'form_params' => $params + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$http, $post];
}

// Zoho, played by templates.
$refreshToken = "zo_refresh_security_$run";
$organizationId = '7' . random_int(100000, 999999);
mkdir("$fake/oauth/v2/token", 0775, true);
mkdir("$fake/api", 0775, true);
file_put_contents("$fake/oauth/v2/token.twig", '{% header "Content-Type: application/json" %}' . json_encode([
    'refresh_token' => $refreshToken, 'access_token' => "zo_access_$run", 'expires_in' => 3600,
]));
file_put_contents("$fake/oauth/v2/token/revoke.twig", '{% header "Content-Type: application/json" %}{"status":"success"}');
file_put_contents("$fake/api/organizations.twig", '{% header "Content-Type: application/json" %}' . json_encode([
    'code' => 0, 'message' => 'success', 'organizations' => [['organization_id' => $organizationId, 'name' => "Security $run", 'currency_code' => 'USD']],
]));

$storeSettings([
    'clientId' => "zo-security-client-$run",
    'clientSecret' => 'zo-security-secret',
    'refreshToken' => '',
    'organizationId' => '',
    'accountsUrl' => "http://localhost/zo-security-$run",
    'apiBaseUrl' => "http://localhost/zo-security-$run/api",
] + $settingsBefore, 'Point Zo at the fake Zoho');
$db->createCommand()->delete(Table::CONNECTION)->execute();

[$adminHttp, $adminPost] = client('admin', 'claudepassword');

echo "\nConnecting, on an environment with admin changes off\n";

$connect = static function() use ($adminHttp): array {
    $response = $adminHttp->get('index.php?p=admin/actions/zo/settings/connect');
    parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);

    return [$response, (string)($query['state'] ?? '')];
};

check('Connect sends the admin to Zoho rather than refusing', fn() => $withAdminChangesOff(function() use ($connect, $run) {
    [$response, $state] = $connect();
    $location = $response->getHeaderLine('Location');

    return $response->getStatusCode() === 302 && str_contains($location, "zo-security-$run/oauth/v2/auth") && $state !== '' ?: 'status ' . $response->getStatusCode() . ' → ' . $location;
}));

check('a callback whose state doesn’t match stores nothing', fn() => $withAdminChangesOff(function() use ($adminHttp, $connect, $connection) {
    $connect();
    $adminHttp->get('index.php?p=admin/zo/oauth/callback&code=abc&state=forged');

    return $connection() === null ?: 'stored';
}));

check('the callback stores the token in Zo’s table, encrypted, and not in project config', fn() => $withAdminChangesOff(function() use ($adminHttp, $connect, $connection, $stored, $refreshToken) {
    [, $state] = $connect();
    $callback = $adminHttp->get('index.php?p=admin/zo/oauth/callback&code=abc&state=' . urlencode($state));
    if ($callback->getStatusCode() !== 302 || !str_contains($callback->getHeaderLine('Location'), 'settings/plugins/zo')) {
        return 'the callback answered ' . $callback->getStatusCode() . ' ' . $callback->getHeaderLine('Location');
    }
    $raw = (string)(new Query())->select('refreshToken')->from(Table::CONNECTION)->scalar();
    $yaml = (string)@file_get_contents(Craft::getAlias('@config') . '/project/project.yaml');

    return ($connection()['refreshToken'] ?? null) === $refreshToken && !str_contains($raw, $refreshToken) && Secret::decrypt($raw) === $refreshToken
        && ($stored('refreshToken') ?? '') === '' && !str_contains($yaml, $refreshToken)
        ?: json_encode(['connection' => $connection(), 'setting' => $stored('refreshToken')]);
}));

check('…picks the only organization, alongside it rather than in the setting', function() use ($connection, $stored, $organizationId) {
    return ($connection()['organizationId'] ?? null) === $organizationId && ($stored('organizationId') ?? '') === '' ?: json_encode($connection());
});

check('Zo reads both from there', function() use ($zo, $connection, $refreshToken, $organizationId) {
    $connection();
    $settings = $zo->getSettings();

    return $settings->getParsedRefreshToken() === $refreshToken && $settings->getParsedOrganizationId() === $organizationId && $settings->getIsConnected() ?: 'not wired';
});

check('an organization chosen in settings still wins', function() use ($zo) {
    $settings = $zo->getSettings();
    $was = $settings->organizationId;
    $settings->organizationId = '99';
    try {
        return $settings->getParsedOrganizationId() === '99' ?: $settings->getParsedOrganizationId();
    } finally {
        $settings->organizationId = $was;
    }
});

check('Disconnect forgets it, with admin changes off too', fn() => $withAdminChangesOff(function() use ($adminPost, $connection) {
    $status = $adminPost('zo/settings/disconnect', [])->getStatusCode();

    return $connection() === null ?: "status $status, still connected";
}));

echo "\nA token stored before 5.0.1\n";

$legacyToken = "zo_legacy_$run";
$storeSettings(['refreshToken' => $legacyToken] + ProjectConfigHelper::unpackAssociativeArray(Craft::$app->getProjectConfig()->get('plugins.zo.settings')), 'A literal token, as 5.0.0 stored it');

check('the migration copies it into the table, encrypted', function() use ($connection, $legacyToken) {
    Craft::$app->getDb()->createCommand()->delete(Table::CONNECTION)->execute();
    (new m261004_000000_connection_table())->safeUp();

    return ($connection()['refreshToken'] ?? null) === $legacyToken ?: json_encode($connection());
});

check('…but not an environment-variable reference', function() use ($connection, $storeSettings) {
    Craft::$app->getDb()->createCommand()->delete(Table::CONNECTION)->execute();
    $current = ProjectConfigHelper::unpackAssociativeArray(Craft::$app->getProjectConfig()->get('plugins.zo.settings'));
    $storeSettings(['refreshToken' => '$ZOHO_REFRESH_TOKEN'] + $current, 'An env reference');
    (new m261004_000000_connection_table())->safeUp();

    return $connection() === null ?: 'copied';
});

$storeSettings(['refreshToken' => $legacyToken] + ProjectConfigHelper::unpackAssociativeArray(Craft::$app->getProjectConfig()->get('plugins.zo.settings')), 'A literal token again');

$model = static function(string $token): Settings {
    $s = new Settings();
    $s->refreshToken = $token;

    return $s;
};

check('the stored literal still validates, so other settings save', fn() => $model($legacyToken)->validate(['refreshToken']) ?: 'refused');
check('a new literal token is refused', fn() => !$model("zo_new_$run")->validate(['refreshToken']) ?: 'accepted');
check('an environment variable is fine', fn() => $model('$ZOHO_REFRESH_TOKEN')->validate(['refreshToken']) ?: 'refused');

check('an empty field posted with the kept flag leaves it alone', function() use ($legacyToken) {
    $s = new Settings();
    $s->refreshToken = $legacyToken;
    $s->setAttributes(['refreshToken' => '', 'refreshTokenKept' => '1', 'maxAttempts' => '7'], false);

    return $s->refreshToken === $legacyToken && (int)$s->maxAttempts === 7 ?: 'changed';
});

check('…unless “Remove it from project config” is ticked', function() use ($legacyToken) {
    $s = new Settings();
    $s->refreshToken = $legacyToken;
    $s->setAttributes(['refreshToken' => '', 'refreshTokenKept' => '1', 'refreshTokenRemove' => '1'], false);

    return $s->refreshToken === '' ?: 'kept';
});

check('Disconnect revokes the old token as well as the connection’s', function() use ($zo, $legacyToken, $refreshToken, $connection) {
    $zo->getConnection()->store($refreshToken, null);
    $original = $zo->get('auth');
    $stub = new class() extends Auth {
        public array $revoked = [];

        public function revokeRefreshToken(?string $token = null): bool
        {
            $this->revoked[] = $token;

            return true;
        }
    };
    $zo->set('auth', $stub);
    try {
        $stub->disconnect();
    } finally {
        $zo->set('auth', $original);
    }
    sort($stub->revoked);
    $expected = [$legacyToken, $refreshToken];
    sort($expected);

    return $stub->revoked === $expected && $connection() === null ?: json_encode($stub->revoked);
});

check('the settings screen never renders it, and says to remove it', function() use ($adminHttp, $legacyToken) {
    $body = (string)$adminHttp->get('index.php?p=admin/settings/plugins/zo')->getBody();

    return !str_contains($body, $legacyToken) && str_contains($body, 'Remove it from project config') && str_contains($body, 'refreshTokenKept')
        ?: json_encode(['shown' => str_contains($body, $legacyToken), 'checkbox' => str_contains($body, 'Remove it from project config')]);
});

check('every element the settings script looks up is on the page — the buttons work', function() use ($adminHttp, $zo, $refreshToken) {
    // Connected, so Test connection and Disconnect render too.
    $zo->getConnection()->store($refreshToken, null);
    $zo->getConnection()->setOrganizationId('1');
    $body = (string)$adminHttp->get('index.php?p=admin/settings/plugins/zo')->getBody();
    $zo->getConnection()->forget();
    preg_match_all("/(?:bind|byId)\\('([\\w-]+)'/", $body, $m);
    $ids = array_values(array_unique(array_filter($m[1], static fn($id) => !str_ends_with($id, '-note'))));
    $missing = array_values(array_filter($ids, static fn($id) => !str_contains($body, 'id="settings-' . $id . '"')));
    $namespaced = str_contains($body, "document.getElementById('settings-' + id)");

    return count($ids) >= 5 && $missing === [] && $namespaced ?: 'ids ' . json_encode($ids) . ', missing ' . json_encode($missing) . ', namespaced lookup ' . var_export($namespaced, true);
});

$saveSettings = static fn(array $values) => $adminPost('plugins/save-plugin-settings', ['pluginHandle' => 'zo', 'settings' => $values]);

check('saving the screen as rendered keeps it', function() use ($saveSettings, $stored, $legacyToken) {
    $status = $saveSettings(['refreshToken' => '', 'refreshTokenKept' => '1'])->getStatusCode();

    return $status === 200 && $stored('refreshToken') === $legacyToken ?: "status $status";
});

check('ticking the box takes it out of project config', function() use ($saveSettings, $stored) {
    $status = $saveSettings(['refreshToken' => '', 'refreshTokenKept' => '1', 'refreshTokenRemove' => '1'])->getStatusCode();

    return $status === 200 && ($stored('refreshToken') ?? '') === '' ?: "status $status, stored " . var_export($stored('refreshToken'), true);
});

echo "\nOrders and the log\n";

$viewer = new User(['username' => "zo-viewer-$run", 'email' => "zo-viewer-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($viewer, false);
Craft::$app->getUsers()->activateUser($viewer);
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'accessplugin-zo', 'zo-viewsync', 'zo-viewlog']);
$cleanup['users'][] = $viewer;
[$viewerHttp, $viewerPost] = client($viewer->username, $password);

// One with an email: the check below looks for it in the response, and `str_contains($body, '')`
// is always true — a sibling's test leaves email-less completed orders in this shared harness.
$order = Order::find()->isCompleted(true)->email(':notempty:')->orderBy(['commerce_orders.dateOrdered' => SORT_DESC])->one();

check('“View what has been synced” can’t read an order’s payload', function() use ($viewerHttp, $order) {
    $response = $viewerHttp->get("index.php?p=admin/actions/zo/sync/preview&orderId={$order->id}", ['headers' => ['Accept' => 'application/json']]);

    return $response->getStatusCode() === 403 && !str_contains((string)$response->getBody(), (string)$order->email) ?: 'status ' . $response->getStatusCode();
});

check('…an admin can', function() use ($adminHttp, $order) {
    $response = $adminHttp->get("index.php?p=admin/actions/zo/sync/preview&orderId={$order->id}", ['headers' => ['Accept' => 'application/json']]);

    return $response->getStatusCode() !== 403 ?: 'status 403';
});

check('“View the log” can’t clear it', function() use ($viewerPost) {
    $status = $viewerPost('zo/log/clear', [])->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('…and isn’t offered the button', function() use ($viewerHttp) {
    $body = (string)$viewerHttp->get('index.php?p=admin/zo/log')->getBody();

    return str_contains($body, 'zo-log') && !str_contains($body, 'id="zo-log-clear"') ?: 'button shown';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
