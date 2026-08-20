<?php

namespace justinholtweb\zo\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;
use justinholtweb\zo\errors\RateLimitException;
use justinholtweb\zo\Plugin;

/**
 * Push one order into Zoho Books, off the request that triggered it.
 *
 * This exists mostly for one reason: the usual trigger is `afterCompleteOrder`, which fires
 * inside the request where a customer has just paid. Three or four calls to a remote accounting
 * API — plus whatever Zoho's rate limiter decides to do about them — has no business happening
 * before the thank-you page renders.
 */
class SyncOrderJob extends BaseJob
{
    public ?int $orderId = null;

    /** Sync even if the eligibility rules would have skipped this order. */
    public bool $force = false;

    /** How many times this order has already come back around. */
    public int $attempt = 1;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if ($this->orderId === null) {
            return;
        }

        $order = Order::find()->id($this->orderId)->status(null)->one();

        if (!$order instanceof Order) {
            // The order was deleted between being queued and being run. Nothing to do, and
            // nothing wrong.
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('zo', 'Syncing order {number}', [
            'number' => $order->reference ?: $order->getShortNumber(),
        ]));

        try {
            $result = Plugin::getInstance()->getSync()->syncOrder($order, $this->force);
        } catch (RateLimitException $e) {
            $this->requeue($e->retryAfter);

            return;
        }

        $this->setProgress($queue, 1);

        if ($result->getIsSuccessful() || !$result->retryable) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();

        if ($this->attempt >= $settings->maxAttempts) {
            // Out of road. Failing loudly here is deliberate: the link row already records the
            // reason, and a job that quietly returns leaves the merchant with an order that is
            // not in the books and no sign that anything went wrong.
            throw new \RuntimeException(implode(' ', $result->getFailures()) ?: Craft::t('zo', 'The sync failed.'));
        }

        // Exponential, capped. Zoho's failures are usually either instant-and-permanent or
        // minutes-long, so backing off past a few minutes buys nothing and delays the books.
        $this->requeue(min(600, 15 * (2 ** ($this->attempt - 1))));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('zo', 'Syncing an order to Zoho Books');
    }

    /**
     * Put this order back in the queue with a delay.
     *
     * A fresh job rather than a queue-level retry, because Craft's queue retries have no backoff:
     * on a rate limit that means hammering the endpoint that just asked to be left alone.
     */
    private function requeue(int $delay): void
    {
        QueueHelper::push(new self([
            'orderId' => $this->orderId,
            'force' => $this->force,
            'attempt' => $this->attempt + 1,
        ]), null, max(1, $delay));
    }
}
