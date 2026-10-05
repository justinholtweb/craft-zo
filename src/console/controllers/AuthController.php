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
            $this->stdout('  Refresh token   ' . self::tokenSource() . PHP_EOL);
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
     * Forget this environment's Zoho connection and revoke its token — what the settings screen's
     * Disconnect button does, for an environment where that screen is read-only.
     */
    public function actionDisconnect(): int
    {
        Plugin::getInstance()->getAuth()->disconnect();
        $this->stdout('Disconnected from Zoho Books.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
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

    private static function tokenSource(): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $connection = $plugin->getConnection()->get();

        return match (true) {
            $connection !== null && $connection['refreshToken'] !== null => 'stored, encrypted (connected on this environment)',
            $connection !== null => 'stored, but can’t be decrypted (security key changed?) — connect again',
            $settings->storesLiteralRefreshToken() => 'in project config — connect again on this environment, then remove it from the settings screen',
            $settings->getParsedRefreshToken() !== '' => 'from ' . trim($settings->refreshToken),
            default => 'missing',
        };
    }
}
