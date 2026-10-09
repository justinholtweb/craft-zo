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
     * Keeps what was chosen, known or not.
     *
     * Stripping unknown statuses here (as 5.0.x did) turned a saved "is one of" whose statuses had
     * all since been renamed or removed into "no filter" — a custom source silently widening to
     * every order — and re-saving the source then lost the stale choice for good. Only
     * {@see knownValues()} ever reaches SQL, so nothing hand-edited can smuggle anything into the
     * query.
     *
     * @param string|string[] $values
     */
    public function setValues(array|string $values): void
    {
        $values = array_filter((array)$values, static fn($value) => is_string($value) || is_int($value));

        parent::setValues(array_values(array_map('strval', $values)));
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
        // Nothing chosen is no filter, as everywhere in Craft.
        if ($this->getValues() === []) {
            return;
        }

        $known = $this->knownValues();

        if ($known === []) {
            // Chosen, but none of it exists any more: "is one of" matches nothing; "is not one of"
            // excludes nothing.
            if ($this->operator !== self::OPERATOR_NOT_IN) {
                $query->andWhere('0=1');
            }

            return;
        }

        $links = Plugin::getInstance()->getLinks();
        $condition = ['or'];

        foreach ($known as $status) {
            $condition[] = $links->orderStatusCondition($status);
        }

        $query->andWhere($this->operator === self::OPERATOR_NOT_IN ? ['not', $condition] : $condition);
    }

    /**
     * @inheritdoc
     */
    public function matchElement(ElementInterface $element): bool
    {
        if ($this->getValues() === []) {
            return true;
        }

        $known = $this->knownValues();

        if ($known === []) {
            return $this->operator === self::OPERATOR_NOT_IN;
        }

        if (!$element instanceof Order || !$element->id) {
            $status = Link::ORDER_NONE;
        } else {
            $status = Plugin::getInstance()->getLinks()->orderStatuses([$element->id])[$element->id] ?? Link::ORDER_NONE;
        }

        $in = in_array($status, $known, true);

        return $this->operator === self::OPERATOR_NOT_IN ? !$in : $in;
    }

    /**
     * The chosen statuses Zo still knows — the only ones that ever reach the query.
     *
     * @return string[]
     */
    private function knownValues(): array
    {
        return array_values(array_intersect($this->getValues(), array_keys(Links::orderStatusOptions())));
    }
}
