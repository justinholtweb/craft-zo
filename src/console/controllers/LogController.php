<?php

namespace justinholtweb\zo\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\zo\Plugin;
use yii\console\ExitCode;

/**
 * Log housekeeping, for a cron entry.
 */
class LogController extends Controller
{
    /** Override the configured retention, in days. */
    public ?int $days = null;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return $actionID === 'prune' ? array_merge($options, ['days']) : $options;
    }

    /**
     * Delete log entries older than the retention period.
     */
    public function actionPrune(): int
    {
        $count = Plugin::getInstance()->getLog()->prune($this->days);

        $this->stdout("{$count} log entries pruned." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Delete every log entry.
     */
    public function actionClear(): int
    {
        if ($this->interactive && !$this->confirm('Delete the entire Zo log?')) {
            return ExitCode::OK;
        }

        $count = Plugin::getInstance()->getLog()->clear();

        $this->stdout("{$count} log entries deleted." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
