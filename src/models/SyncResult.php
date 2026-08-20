<?php

namespace justinholtweb\zo\models;

use craft\base\Model;

/**
 * What happened when an order was pushed to Zoho Books.
 *
 * Syncing an order is not one call — it is a contact, optionally some items, an invoice, then any
 * payments and refunds — and any of those can succeed while a later one fails. A single
 * boolean would throw that away, so every step reports itself and the caller decides what to
 * show.
 */
class SyncResult extends Model
{
    public const STEP_OK = 'ok';
    public const STEP_SKIPPED = 'skipped';
    public const STEP_FAILED = 'failed';
    /** Already synced on an earlier run; nothing was sent. */
    public const STEP_REUSED = 'reused';

    public ?int $orderId = null;
    public ?string $orderNumber = null;

    /**
     * @var array<int, array{step: string, outcome: string, message: string, zohoId: ?string}>
     */
    public array $steps = [];

    public ?string $contactId = null;
    public ?string $invoiceId = null;
    public ?string $invoiceNumber = null;

    /**
     * Commerce's total vs the total Zoho Books came back with.
     */
    public ?float $craftTotal = null;
    public ?float $zohoTotal = null;
    public ?float $variance = null;

    /**
     * Whether anything at all should be retried later.
     */
    public bool $retryable = false;

    public function addStep(string $step, string $outcome, string $message = '', ?string $zohoId = null): void
    {
        $this->steps[] = [
            'step' => $step,
            'outcome' => $outcome,
            'message' => $message,
            'zohoId' => $zohoId,
        ];
    }

    public function getIsSuccessful(): bool
    {
        foreach ($this->steps as $step) {
            if ($step['outcome'] === self::STEP_FAILED) {
                return false;
            }
        }

        return $this->steps !== [];
    }

    /**
     * The messages from the steps that failed.
     *
     * Deliberately not `getErrors()`: `yii\base\Model` already declares that with an
     * `$attribute` parameter for validation errors, and redeclaring it with a different signature
     * is a **compile-time fatal** — the class cannot even be loaded, so the failure appears the
     * first time anything syncs rather than in review.
     *
     * @return string[]
     */
    public function getFailures(): array
    {
        $errors = [];

        foreach ($this->steps as $step) {
            if ($step['outcome'] === self::STEP_FAILED && $step['message'] !== '') {
                $errors[] = $step['message'];
            }
        }

        return $errors;
    }

    /**
     * A one-line summary for the log, the CP flash and the console.
     */
    public function getSummary(): string
    {
        if ($this->steps === []) {
            return 'Nothing to do';
        }

        if (!$this->getIsSuccessful()) {
            return implode(' ', $this->getFailures()) ?: 'Sync failed';
        }

        $parts = [];

        foreach ($this->steps as $step) {
            if ($step['outcome'] === self::STEP_OK) {
                $parts[] = $step['step'];
            }
        }

        if ($parts === []) {
            return 'Already up to date';
        }

        return 'Synced ' . implode(', ', $parts);
    }

    public function getHasVariance(float $tolerance = 0.005): bool
    {
        return $this->variance !== null && abs($this->variance) > $tolerance;
    }
}
