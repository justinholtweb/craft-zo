<?php

namespace justinholtweb\zo\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;
use justinholtweb\zo\errors\RateLimitException;
use justinholtweb\zo\Plugin;

/**
 * Walk the store's history into Zoho Books, a batch at a time.
 *
 * Deliberately not one job per order: a store connecting Zo after two years of trading has
 * thousands of orders, and queueing thousands of jobs makes the queue unusable for everything
 * else. Deliberately not one job for all of them either — Zoho allows a few thousand calls a day,
 * so a backfill that ignored that would spend its afternoon being refused.
 */
class BackfillJob extends BaseJob
{
    /** Orders to sync in this batch. */
    public int $batchSize = 25;

    /** Only orders placed on or after this date, as `Y-m-d H:i:s`. */
    public ?string $since = null;

    /** Keep queueing follow-on batches until nothing is left. */
    public bool $continue = true;

    /** Safety rail, so a bug in the "is it synced" test cannot produce an endless chain. */
    public int $batch = 1;

    public const MAX_BATCHES = 400;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $since = $this->since !== null ? new \DateTime($this->since) : null;
        $orders = $plugin->getSync()->getUnsyncedOrders($since, $this->batchSize);

        if ($orders === []) {
            return;
        }

        $total = count($orders);
        $done = 0;
        $rateLimited = false;

        foreach ($orders as $order) {
            /** @var Order $order */
            $this->setProgress($queue, $done / $total, Craft::t('zo', 'Order {number}', [
                'number' => $order->reference ?: $order->getShortNumber(),
            ]));

            try {
                $plugin->getSync()->syncOrder($order);
            } catch (RateLimitException) {
                // Stop the batch, not the backfill. Continuing would spend the rest of the
                // allowance being told no.
                $rateLimited = true;
                break;
            } catch (\Throwable $e) {
                // A single unsyncable order must not end the backfill; its link row holds why.
                Craft::warning('Zo backfill skipped an order: ' . $e->getMessage(), __METHOD__);
            }

            $done++;
        }

        $this->setProgress($queue, 1);

        if (!$this->continue || $this->batch >= self::MAX_BATCHES) {
            return;
        }

        QueueHelper::push(new self([
            'batchSize' => $this->batchSize,
            'since' => $this->since,
            'continue' => true,
            'batch' => $this->batch + 1,
        ]), null, $rateLimited ? 300 : 5);
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('zo', 'Backfilling orders to Zoho Books');
    }
}
