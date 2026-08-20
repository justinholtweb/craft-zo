<?php

namespace justinholtweb\zo\twig;

use craft\commerce\elements\Order;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\Plugin;
use yii\base\Behavior;

/**
 * `craft.zo` — read-only, on purpose.
 *
 * A template is not a place from which to create an invoice: it renders during a request that may
 * be a cached page, a preview, or a bot, and any of those would put a document in the merchant's
 * books. Everything here answers questions instead.
 */
class ZoVariable extends Behavior
{
    /**
     * Whether Zo has working credentials.
     */
    public function isConnected(): bool
    {
        return Plugin::getInstance()->getSettings()->getIsConnected();
    }

    /**
     * The Zoho invoice link for an order, if it has one.
     */
    public function invoiceFor(Order|int|null $order): ?Link
    {
        $id = $order instanceof Order ? $order->id : $order;

        if ($id === null) {
            return null;
        }

        return Plugin::getInstance()->getLinks()->find(Link::TYPE_INVOICE, Link::key('order', (int)$id));
    }

    /**
     * The Zoho invoice number for an order, for a "your invoice is INV-000123" line on an order
     * confirmation.
     */
    public function invoiceNumberFor(Order|int|null $order): ?string
    {
        $link = $this->invoiceFor($order);

        return $link !== null && $link->getIsSynced() ? $link->zohoNumber : null;
    }

    /**
     * Every link belonging to an order.
     *
     * @return Link[]
     */
    public function linksFor(Order|int|null $order): array
    {
        $id = $order instanceof Order ? $order->id : $order;

        if ($id === null) {
            return [];
        }

        return Plugin::getInstance()->getLinks()->getLinksForElement((int)$id);
    }

    /**
     * @return array{synced: int, pending: int, failed: int, skipped: int, variance: int}
     */
    public function stats(): array
    {
        return Plugin::getInstance()->getLinks()->getStats();
    }
}
