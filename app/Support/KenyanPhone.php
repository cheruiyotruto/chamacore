<?php

namespace App\Support;

/**
 * Normalises Kenyan MSISDNs used by M-Pesa to the 2547XXXXXXXX form.
 */
final class KenyanPhone
{
    public static function normalise(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        $digits = ltrim($digits, '0');

        if (strlen($digits) === 9) {
            $digits = '254'.$digits;
        }

        if (! str_starts_with($digits, '254') || strlen($digits) !== 12) {
            return null;
        }

        return $digits;
    }

    public static function isValid(string $phone): bool
    {
        return self::normalise($phone) !== null;
    }
}
