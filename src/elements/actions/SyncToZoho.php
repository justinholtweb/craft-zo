<?php

namespace justinholtweb\zo\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Queue;
use justinholtweb\zo\jobs\SyncOrderJob;
use justinholtweb\zo\Plugin;

/**
 * "Sync to Zoho Books" on the Orders index: queue every selected, completed order.
 *
 * Forced, like the order screen's "Sync now" — choosing orders by hand is the merchant overriding
 * the eligibility rules. Always through the queue, even with `syncViaQueue` off: a hundred orders
 * inline is a request that times out halfway. Already-synced documents are reused, never resent,
 * so selecting a synced order is harmless; it picks up any payment or refund that is missing.
 */
class SyncToZoho extends ElementAction
{
    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('zo', 'Sync to Zoho Books');
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        // The action is only offered to people who may sync, but the request can be made by
        // anyone who can see the index.
        if (!Craft::$app->getUser()->checkPermission('zo-syncOrders')) {
            $this->setMessage(Craft::t('zo', 'You are not allowed to sync orders to Zoho Books.'));

            return false;
        }

        if (!Plugin::getInstance()->getSettings()->getIsConnected()) {
            $this->setMessage(Craft::t('zo', 'Zo is not connected to Zoho Books.'));

            return false;
        }

        $ids = (clone $query)->status(null)->ids();
        // A cart is not a sale; there is nothing to invoice.
        $completed = $ids === [] ? [] : Order::find()->id($ids)->status(null)->isCompleted(true)->ids();

        foreach ($completed as $id) {
            Queue::push(new SyncOrderJob(['orderId' => (int)$id, 'force' => true]));
        }

        $queued = count($completed);
        $skipped = count($ids) - $queued;

        $this->setMessage($skipped > 0
            ? Craft::t('zo', '{queued} orders queued for Zoho Books; {skipped} incomplete orders skipped.', ['queued' => $queued, 'skipped' => $skipped])
            : Craft::t('zo', '{queued} orders queued for Zoho Books.', ['queued' => $queued]));

        return $queued > 0;
    }
}
