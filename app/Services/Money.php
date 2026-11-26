<?php

namespace App\Services;

/**
 * Money helpers. All amounts are integer nanodollars (1 USD = 1_000_000_000).
 *
 * Pixelforge costs are tokens x rate / 1M. With rates quoted to cents that is
 * always a whole number of nanodollars, so integers lose nothing.
 */
final class Money
{
    public const NANO_PER_USD = 1_000_000_000;

    /** Convert a decimal-string USD rate per 1M tokens into nanodollars per token. */
    public static function rateToNanoPerToken(string $usdPerMillion): int
    {
        // $X per 1M tokens = X * 1e9 / 1e6 nano per token = X * 1000.
        return (int) round(((float) $usdPerMillion) * 1000);
    }

    /** Apply the operator markup (rounded to the nearest nanodollar). */
    public static function withMarkup(int $costNano): int
    {
        $percent = 100 + (int) config('pixelforge.markup_percent');

        return intdiv($costNano * $percent + 50, 100);
    }

    /** Whole USD -> nanodollars. */
    public static function usdToNano(int $usd): int
    {
        return $usd * self::NANO_PER_USD;
    }

    /** Exact 9-decimal string, matching Pixelforge's cost_usd format. */
    public static function toDecimal(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $abs = abs($nano);

        return sprintf('%s%d.%09d', $sign, intdiv($abs, self::NANO_PER_USD), $abs % self::NANO_PER_USD);
    }
}
