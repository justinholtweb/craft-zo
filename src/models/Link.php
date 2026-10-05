<?php

namespace justinholtweb\zo\models;

use craft\base\Model;
use DateTime;

/**
 * One row of the Craft ↔ Zoho Books id map.
 *
 * Every document Zo creates is represented here before it is created and updated after, which is
 * what makes the whole integration replayable: a link in `pending` is work that was started, a
 * link in `synced` is work that must never be repeated, and a link in `failed` carries the reason
 * in a form a merchant can read.
 */
class Link extends Model
{
    // Types
    // -------------------------------------------------------------------------

    public const TYPE_CONTACT = 'contact';
    public const TYPE_ITEM = 'item';
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_SALESORDER = 'salesorder';
    public const TYPE_PAYMENT = 'payment';
    /**
     * A Commerce refund transaction. It reaches Zoho either as a refund against the customer
     * payment it came from — the accurate mapping, because it reverses a specific receipt — or,
     * when that payment was never linked, as a credit note. One type covers both: what matters to
     * the id map is that this refund has been recorded once, not which door it went through.
     */
    public const TYPE_REFUND = 'refund';

    // Statuses
    // -------------------------------------------------------------------------

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    /** Deliberately not sent — an order below the sync threshold, a $0 payment, and so on. */
    public const STATUS_SKIPPED = 'skipped';

    public ?int $id = null;
    public string $type = '';
    public string $craftKey = '';
    public ?int $elementId = null;
    public ?string $zohoId = null;
    public ?string $zohoNumber = null;
    public string $status = self::STATUS_PENDING;
    public int $attempts = 0;
    public ?string $lastError = null;
    public ?float $craftTotal = null;
    public ?float $zohoTotal = null;
    public ?float $variance = null;
    public ?DateTime $dateSynced = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        // Dates need nothing here: craft\base\Model typecasts the config against the typed
        // properties before they are assigned.
        parent::init();

        foreach (['craftTotal', 'zohoTotal', 'variance'] as $attribute) {
            if ($this->$attribute !== null) {
                $this->$attribute = (float)$this->$attribute;
            }
        }
    }

    /**
     * All link types, for validation and for the CP filters.
     *
     * @return string[]
     */
    public static function types(): array
    {
        return [
            self::TYPE_CONTACT,
            self::TYPE_ITEM,
            self::TYPE_INVOICE,
            self::TYPE_SALESORDER,
            self::TYPE_PAYMENT,
            self::TYPE_REFUND,
        ];
    }

    public function getIsSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED && $this->zohoId !== null && $this->zohoId !== '';
    }

    /**
     * Whether Zoho's total for this document disagrees with Commerce's by more than a rounding
     * hair. Anything true here needs a human: the customer paid one number and the books say
     * another.
     */
    public function getHasVariance(float $tolerance = 0.005): bool
    {
        return $this->variance !== null && abs($this->variance) > $tolerance;
    }

    /**
     * The Craft-side id embedded in the key, e.g. `1042` for `order:1042`.
     */
    public function getCraftId(): ?int
    {
        $position = strpos($this->craftKey, ':');

        if ($position === false) {
            return null;
        }

        $value = substr($this->craftKey, $position + 1);

        return ctype_digit($value) ? (int)$value : null;
    }

    /**
     * Build a canonical key. The single place the key format is decided, so a typo cannot silently
     * create a second identity for something already synced.
     */
    public static function key(string $kind, int|string $id): string
    {
        return $kind . ':' . $id;
    }
}
