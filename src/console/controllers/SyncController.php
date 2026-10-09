<?php

namespace justinholtweb\zo\console\controllers;

use craft\commerce\elements\Order;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Queue;
use justinholtweb\zo\jobs\BackfillJob;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\models\SyncResult;
use justinholtweb\zo\Plugin;
use yii\console\ExitCode;

/**
 * Syncing from the command line.
 *
 * Reachable as `craft zo/sync/…`. Craft does not surface plugin commands under
 * `craft help zo`, so they are listed by a bare `craft help`.
 */
class SyncController extends Controller
{
    /** Sync even orders the eligibility rules would skip. */
    public bool $force = false;

    /** Only orders placed on or after this date (`Y-m-d`). */
    public ?string $since = null;

    /** How many orders to work through. */
    public int $limit = 100;

    /** Show what would be sent without sending anything. */
    public bool $dryRun = false;

    /** Hand the work to the queue instead of doing it here. */
    public bool $queue = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        return match ($actionID) {
            'order' => array_merge($options, ['force', 'dryRun']),
            'backfill' => array_merge($options, ['since', 'limit', 'dryRun', 'queue']),
            'retry' => array_merge($options, ['limit']),
            default => $options,
        };
    }

    /**
     * Sync one order to Zoho Books.
     *
     * `craft zo/sync/order 1042` takes an order id; `craft zo/sync/order --force` overrides the
     * eligibility rules.
     */
    public function actionOrder(int $orderId): int
    {
        $order = Order::find()->id($orderId)->status(null)->one();

        if (!$order instanceof Order) {
            $this->stderr("No order with id {$orderId}." . PHP_EOL, Console::FG_RED);

            return ExitCode::DATAERR;
        }

        if ($this->dryRun) {
            $preview = Plugin::getInstance()->getSync()->preview($order);
            $this->stdout(json_encode($preview['invoice'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            $this->stdout(sprintf(
                'Projected total %s · Commerce total %s%s',
                number_format($preview['projectedTotal'], 2),
                number_format($preview['craftTotal'], 2),
                PHP_EOL
            ));

            return ExitCode::OK;
        }

        $result = Plugin::getInstance()->getSync()->syncOrder($order, $this->force);
        $this->printResult($result);

        return $result->getIsSuccessful() ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sync every completed order that has never reached Zoho Books.
     */
    public function actionBackfill(): int
    {
        $plugin = Plugin::getInstance();
        $since = $this->since !== null ? new \DateTime($this->since) : null;

        if ($this->queue) {
            Queue::push(new BackfillJob([
                'since' => $since?->format('Y-m-d H:i:s'),
            ]));
            $this->stdout('Backfill queued.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        $orders = $plugin->getSync()->getUnsyncedOrders($since, $this->limit);

        if ($orders === []) {
            $this->stdout('Nothing to backfill.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stdout(sprintf('%d order(s) to sync.%s', count($orders), PHP_EOL));

        if ($this->dryRun) {
            foreach ($orders as $order) {
                $this->stdout(sprintf(
                    '  %s  %s%s',
                    str_pad($order->reference ?: $order->getShortNumber(), 16),
                    number_format($order->getTotalPrice(), 2),
                    PHP_EOL
                ));
            }

            return ExitCode::OK;
        }

        $counts = ['ok' => 0, 'failed' => 0];

        foreach ($orders as $order) {
            $result = $plugin->getSync()->syncOrder($order);
            $label = $order->reference ?: $order->getShortNumber();

            if ($result->getIsSuccessful()) {
                $counts['ok']++;
                $this->stdout("  ✓ {$label} " . $result->getSummary() . PHP_EOL, Console::FG_GREEN);
            } else {
                $counts['failed']++;
                $this->stdout("  ✗ {$label} " . $result->getSummary() . PHP_EOL, Console::FG_RED);
            }
        }

        $this->stdout(sprintf('%d synced, %d failed.%s', $counts['ok'], $counts['failed'], PHP_EOL));

        return $counts['failed'] === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Try failed orders again.
     */
    public function actionRetry(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $links = $plugin->getLinks()->getRetryable(Link::TYPE_INVOICE, $settings->maxAttempts, $this->limit);

        if ($links === []) {
            $this->stdout('Nothing to retry.' . PHP_EOL, Console::FG_GREEN);
            $this->checkAlerts();

            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($links as $link) {
            if ($link->elementId === null) {
                continue;
            }

            $order = Order::find()->id($link->elementId)->status(null)->one();

            if (!$order instanceof Order) {
                continue;
            }

            $result = $plugin->getSync()->syncOrder($order, true);
            $label = $order->reference ?: $order->getShortNumber();

            if ($result->getIsSuccessful()) {
                $this->stdout("  ✓ {$label}" . PHP_EOL, Console::FG_GREEN);
            } else {
                $failed++;
                $this->stdout("  ✗ {$label} " . $result->getSummary() . PHP_EOL, Console::FG_RED);
            }
        }

        $this->checkAlerts();

        return $failed === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Cron runs this when nothing else is running, so it is where an incident is seen to clear.
     */
    private function checkAlerts(): void
    {
        foreach (Plugin::getInstance()->getAlerts()->check() as $result) {
            if ($result['transition'] !== null) {
                $this->stdout(sprintf('Alert %s: %s%s', $result['transition'], $result['incident'], PHP_EOL), Console::FG_YELLOW);
            }
        }
    }

    /**
     * How much of the store is in the books.
     */
    public function actionStatus(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $stats = $plugin->getLinks()->getStats();

        $this->stdout('Zoho Books' . PHP_EOL, Console::FG_CYAN);
        $this->stdout('  Connected      ' . ($settings->getIsConnected() ? 'yes' : 'no') . PHP_EOL);
        $this->stdout('  Data centre    ' . $settings->getSafeDataCenter() . PHP_EOL);
        $this->stdout('  Organization   ' . ($settings->getParsedOrganizationId() ?: '—') . PHP_EOL);
        $this->stdout('  Tax mode       ' . $settings->taxMode . PHP_EOL);
        $this->stdout(PHP_EOL . 'Documents' . PHP_EOL, Console::FG_CYAN);
        $this->stdout('  Synced         ' . $stats['synced'] . PHP_EOL);
        $this->stdout('  Pending        ' . $stats['pending'] . PHP_EOL);
        $this->stdout('  Failed         ' . $stats['failed'] . PHP_EOL, $stats['failed'] > 0 ? Console::FG_RED : null);
        $this->stdout('  Skipped        ' . $stats['skipped'] . PHP_EOL);
        $this->stdout('  Not reconciled ' . $stats['variance'] . PHP_EOL, $stats['variance'] > 0 ? Console::FG_YELLOW : null);

        $unsynced = $plugin->getSync()->countUnsyncedOrders();
        $this->stdout(PHP_EOL . '  Orders awaiting sync ' . $unsynced['count'] . ($unsynced['exact'] ? '' : '+') . PHP_EOL);

        return ExitCode::OK;
    }

    private function printResult(SyncResult $result): void
    {
        foreach ($result->steps as $step) {
            $colour = match ($step['outcome']) {
                SyncResult::STEP_OK => Console::FG_GREEN,
                SyncResult::STEP_FAILED => Console::FG_RED,
                default => Console::FG_GREY,
            };

            $this->stdout(sprintf(
                '  %-14s %-8s %s%s',
                $step['step'],
                $step['outcome'],
                $step['message'],
                PHP_EOL
            ), $colour);
        }

        if ($result->getHasVariance()) {
            $this->stdout(sprintf(
                '  Variance: Zoho %s vs Commerce %s%s',
                number_format((float)$result->zohoTotal, 2),
                number_format((float)$result->craftTotal, 2),
                PHP_EOL
            ), Console::FG_YELLOW);
        }
    }
}
