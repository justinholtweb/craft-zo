<?php

namespace justinholtweb\zo\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\zo\Plugin;
use yii\console\ExitCode;

/**
 * Checking the Zoho Books connection from the command line.
 *
 * There is deliberately no command to *make* a connection: the OAuth flow needs a browser, and a
 * console command that asked an operator to paste a refresh token would encourage exactly the
 * habit — credentials in shell history — that the env-var advice exists to prevent.
 */
class AuthController extends Controller
{
    /**
     * Check that Zo can reach Zoho Books, and say which organization it lands in.
     */
    public function actionTest(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->getIsConnected()) {
            $this->stderr('Zo is not connected.' . PHP_EOL, Console::FG_RED);
            $this->stdout('  Client ID       ' . ($settings->getParsedClientId() !== '' ? 'set' : 'missing') . PHP_EOL);
            $this->stdout('  Client secret   ' . ($settings->getParsedClientSecret() !== '' ? 'set' : 'missing') . PHP_EOL);
            $this->stdout('  Refresh token   ' . ($settings->getParsedRefreshToken() !== '' ? 'set' : 'missing') . PHP_EOL);
            $this->stdout('  Organization ID ' . ($settings->getParsedOrganizationId() !== '' ? 'set' : 'missing') . PHP_EOL);

            return ExitCode::CONFIG;
        }

        $result = $plugin->getApi()->testConnection();

        $this->stdout($result['message'] . PHP_EOL, $result['success'] ? Console::FG_GREEN : Console::FG_RED);

        foreach ($result['organizations'] ?? [] as $organization) {
            $this->stdout(sprintf('  %-14s %s%s', $organization['id'], $organization['name'], PHP_EOL));
        }

        return $result['success'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Force a token refresh, to prove the refresh token still works.
     */
    public function actionRefresh(): int
    {
        try {
            Plugin::getInstance()->getAuth()->getAccessToken(true);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Access token refreshed.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
