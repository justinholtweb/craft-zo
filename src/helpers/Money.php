<?php

namespace justinholtweb\zo\helpers;

/**
 * Currency arithmetic, kept in one place.
 *
 * Every number Zo sends is a price that a customer has already been charged, so "close enough"
 * is not a standard that applies: an invoice that is a cent out is an invoice the merchant's
 * accountant has to chase.
 */
abstract class Money
{
    /**
     * Round the way money rounds.
     *
     * `round()` in PHP is half-up on the decimal representation, which is what invoices use;
     * banker's rounding would be wrong here even though it is statistically nicer.
     */
    public static function round(float $amount, int $precision = 2): float
    {
        return round($amount, $precision);
    }

    /**
     * Whether two amounts are the same money.
     *
     * Float comparison with `===` on prices is a bug that hides for months: 0.1 + 0.2 is not 0.3,
     * and a total assembled from twelve line items is never bit-identical to the one the other
     * system assembled from the same twelve.
     */
    public static function equal(float $a, float $b, float $tolerance = 0.005): bool
    {
        return abs($a - $b) <= $tolerance;
    }

    /**
     * Format for display in the CP, without pulling in a currency formatter.
     */
    public static function format(float $amount, string $currency = ''): string
    {
        $formatted = number_format($amount, 2);

        return $currency !== '' ? "{$formatted} {$currency}" : $formatted;
    }
}
