<?php

namespace justinholtweb\zo\widgets;

use Craft;
use craft\base\Widget;
use justinholtweb\zo\Plugin;

/**
 * A dashboard tile: whether Zo is connected, how much of the store is in the books, and any open
 * alert.
 *
 * The Sync screen is where the detail lives; this is what makes somebody go there. It shows the
 * same latch rows the alert emails come from, so the tile and the inbox cannot disagree.
 */
class HealthWidget extends Widget
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('zo', 'Zoho Books health');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@justinholtweb/zo/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('zo-viewSync');
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        return Craft::t('zo', 'Zoho Books health');
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        // A widget outlives the permission that let somebody add it.
        if (!Craft::$app->getUser()->checkPermission('zo-viewSync')) {
            return null;
        }

        return Craft::$app->getView()->renderTemplate('zo/_widgets/health', [
            'overview' => Plugin::getInstance()->getAlerts()->overview(),
        ]);
    }
}
