<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is always handled as integer minor units. This helper is the only
 * sanctioned boundary between user-facing decimal strings and stored cents.
 */
final class Money
{
    public static function format(?int $minor, string $currency = 'KES', bool $withSymbol = true): string
    {
        $minor ??= 0;
        $negative = $minor < 0;
        $absolute = abs($minor);
        $formatted = number_format($absolute / 100, 2, '.', ',');

        return ($negative ? '-' : '').($withSymbol ? $currency.' ' : '').$formatted;
    }

    public static function toMinor(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        $value = trim((string) $value);
        $value = str_replace([',', ' ', 'KES', 'Ksh', 'KSh'], '', $value);

        if ($value === '') {
            return 0;
        }

        if (! preg_match('/^-?\d+(\.\d{1,4})?$/', $value)) {
            throw new InvalidArgumentException("Invalid monetary value [{$value}].");
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = substr(str_pad($fraction, 3, '0'), 0, 3);
        $roundUp = ((int) $fraction[2]) >= 5;
        $cents = ((int) $whole * 100) + (int) substr($fraction, 0, 2) + ($roundUp ? 1 : 0);

        return $negative ? -$cents : $cents;
    }

    public static function percentFromBps(int $bps): string
    {
        return number_format($bps / 100, 2).'%';
    }

    public static function multiplier(int $bps): string
    {
        return number_format($bps / 10000, 2).'x';
    }
}
