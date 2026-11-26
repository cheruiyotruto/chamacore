<?php

namespace App\Support;

/**
 * Helpers for keeping secrets and personal data out of application logs.
 */
class LogRedaction
{
    /**
     * Keep enough of a phone number to trace a delivery without storing it.
     */
    public static function recipient(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) > 3 ? '***'.substr($digits, -3) : '***';
    }

    /**
     * Replace signed URLs, bearer-style tokens and query strings with markers.
     */
    public static function text(string $value): string
    {
        $value = preg_replace('#https?://\S+#i', '[url redacted]', $value) ?? $value;
        $value = preg_replace('/\b[A-Za-z0-9_-]{32,}\b/', '[token redacted]', $value) ?? $value;

        return mb_strimwidth($value, 0, 200, '…');
    }
}
