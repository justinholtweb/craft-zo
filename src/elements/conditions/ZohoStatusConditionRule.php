<?php

namespace justinholtweb\zo\elements\conditions;

use Craft;
use craft\base\conditions\BaseMultiSelectConditionRule;
use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use justinholtweb\zo\models\Link;
use justinholtweb\zo\Plugin;
use justinholtweb\zo\services\Links;

/**
 * "Zoho Books status" on Commerce's Orders index filters (and anywhere else an order condition is
 * built: custom sources, discounts, shipping rules).
 *
 * The query side and the element side are both {@see Links}' order-status sets, so a custom
 * source "Failed in Zoho" and the Zoho column on the same rows can never disagree.
 *
 * Registered unconditionally — never behind a setting or a permission: Craft drops an
 * unregistered rule from a saved condition, and a custom source would silently widen to every
 * order.
 */
class ZohoStatusConditionRule extends BaseMultiSelectConditionRule implements ElementConditionRuleInterface
{
    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return Craft::t('zo', 'Zoho Books status');
    }

    /**
     * @inheritdoc
     */
    public function getExclusiveQueryParams(): array
    {
        return [];
    }

    /**
     * Only the statuses Zo knows: a hand-edited or stale condition cannot smuggle anything else
     * into the query.
     *
     * @param string|string[] $values
     */
    public function setValues(array|string $values): void
    {
        $known = array_keys(Links::orderStatusOptions());

        parent::setValues(array_values(array_intersect((array)$values, $known)));
    }

    /**
     * @inheritdoc
     */
    protected function options(): array
    {
        $options = [];

        foreach (Links::orderStatusOptions() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function modifyQuery(ElementQueryInterface $query): void
    {
        $values = $this->getValues();

        if ($values === []) {
            return;
        }

        $links = Plugin::getInstance()->getLinks();
        $condition = ['or'];

        foreach ($values as $status) {
            $condition[] = $links->orderStatusCondition($status);
        }

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? ['not', $condition] : $condition);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        if (!$element instanceof Order || !$element->id) {
            return $this->matchValue(Link::ORDER_NONE);
        }

        $status = Plugin::getInstance()->getLinks()->orderStatuses([$element->id])[$element->id] ?? Link::ORDER_NONE;

        return $this->matchValue($status);
    }
}
