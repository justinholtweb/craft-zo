<?php

namespace justinholtweb\zo\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use justinholtweb\zo\errors\ZohoApiException;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\Plugin;

/**
 * Purchasables as Zoho Books items (Pro).
 *
 * Optional on purpose. An invoice whose lines are plain text totals correctly and posts to the
 * right accounts; linking each line to a Zoho item is what makes Zoho's own sales-by-item
 * reporting work, and that is worth an extra API call per new product but not per order.
 */
class Items extends Component
{
    /** Zoho's "an item with that name already exists" code. */
    public const CODE_DUPLICATE_NAME = 1001;

    /**
     * Zoho item ids for everything on this order, keyed by purchasable id.
     *
     * Never throws. An item that cannot be created leaves its line as free text, which is a
     * slightly poorer invoice — as against no invoice at all, which is what raising here would
     * produce.
     *
     * @return array<int, string>
     */
    public function syncForOrder(Order $order): array
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro() || !$plugin->getSettings()->syncItems) {
            return [];
        }

        $ids = [];

        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->purchasableId === null) {
                continue;
            }

            // A cart can hold the same purchasable on two lines with different options.
            if (isset($ids[$lineItem->purchasableId])) {
                continue;
            }

            $itemId = $this->syncLineItem($lineItem);

            if ($itemId !== null) {
                $ids[$lineItem->purchasableId] = $itemId;
            }
        }

        return $ids;
    }

    /**
     * The Zoho item id for one line item's purchasable, or null if it could not be established.
     */
    public function syncLineItem(LineItem $lineItem): ?string
    {
        $purchasableId = $lineItem->purchasableId;

        if ($purchasableId === null) {
            return null;
        }

        $links = Plugin::getInstance()->getLinks();
        $link = $links->claim(Link::TYPE_ITEM, Link::key('purchasable', $purchasableId), $purchasableId);

        if ($link->getIsSynced()) {
            return (string)$link->zohoId;
        }

        $settings = Plugin::getInstance()->getSettings();
        $documents = Plugin::getInstance()->getDocuments();
        $payload = $documents->buildItemPayload($lineItem);

        // Match before creating. A store connecting Zo probably already sells these products in
        // Zoho, and a second item with the same SKU splits every report that uses it.
        $existing = $settings->itemMatchBy === 'name'
            ? $this->findByName((string)($payload['name'] ?? ''))
            : $this->findBySku($lineItem->getSku());

        if ($existing !== null) {
            $links->markSynced($link, $existing['id'], $existing['name']);

            return $existing['id'];
        }

        try {
            $response = Plugin::getInstance()->getApi()->post('items', $payload, [], [
                'action' => 'item',
                'elementId' => $purchasableId,
            ]);
        } catch (ZohoApiException $e) {
            if ($e->zohoCode === self::CODE_DUPLICATE_NAME) {
                $existing = $this->findByName((string)($payload['name'] ?? ''));

                if ($existing !== null) {
                    $links->markSynced($link, $existing['id'], $existing['name']);

                    return $existing['id'];
                }
            }

            $links->markFailed($link, $e->getMessage());
            Craft::warning('Zo could not create a Zoho item: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        $item = $response['item'] ?? [];
        $itemId = (string)($item['item_id'] ?? '');

        if ($itemId === '') {
            $links->markFailed($link, Craft::t('zo', 'Zoho created an item but returned no ID for it.'));

            return null;
        }

        $links->markSynced($link, $itemId, (string)($item['name'] ?? ''));

        return $itemId;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function findBySku(string $sku): ?array
    {
        if (trim($sku) === '') {
            return null;
        }

        return $this->search(['sku' => trim($sku)]);
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function findByName(string $name): ?array
    {
        if (trim($name) === '') {
            return null;
        }

        return $this->search(['name' => trim($name)]);
    }

    // Private
    // =========================================================================

    /**
     * @param array<string, string> $query
     * @return array{id: string, name: string}|null
     */
    private function search(array $query): ?array
    {
        try {
            $response = Plugin::getInstance()->getApi()->get('items', $query + ['per_page' => 1], [
                'action' => 'item-lookup',
            ]);
        } catch (\Throwable $e) {
            Craft::warning('Zo could not search Zoho items: ' . $e->getMessage(), __METHOD__);

            return null;
        }

        foreach ($response['items'] ?? [] as $item) {
            $id = (string)($item['item_id'] ?? '');

            if ($id !== '') {
                return ['id' => $id, 'name' => (string)($item['name'] ?? '')];
            }
        }

        return null;
    }
}
