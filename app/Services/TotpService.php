<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * RFC 6238 TOTP implementation (SHA-1, 6 digits, 30 second period).
 */
class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        return $this->verifySlice($secret, $code, $window) !== null;
    }

    /**
     * Verify a code and reject replay of a code that was already accepted in
     * an earlier (or the same) 30-second time slice.
     */
    public function verifyOnce(string $secret, string $code, int $userId, int $window = 1): bool
    {
        $slice = $this->verifySlice($secret, $code, $window);

        if ($slice === null) {
            return false;
        }

        $key = 'mfa:last-slice:'.$userId;

        if ($slice <= (int) Cache::get($key, 0)) {
            return false;
        }

        Cache::put($key, $slice, now()->addMinutes(10));

        return true;
    }

    public function forgetReplayGuard(int $userId): void
    {
        Cache::forget('mfa:last-slice:'.$userId);
    }

    private function verifySlice(string $secret, string $code, int $window = 1): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', (string) $code)) {
            return null;
        }

        $timeSlice = (int) floor(time() / 30);

        for ($i = -$window; $i <= $window; $i++) {
            $slice = $timeSlice + $i;

            if (hash_equals($this->code($secret, $slice), (string) $code)) {
                return $slice;
            }
        }

        return null;
    }

    public function currentCode(string $secret): string
    {
        return $this->code($secret, (int) floor(time() / 30));
    }

    public function provisioningUri(string $secret, string $account, string $issuer = 'Chama Core'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
    }

    private function code(string $secret, int $timeSlice): string
    {
        $key = $this->base32Decode($secret);
        $binary = pack('N*', 0).pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret));
        $binary = '';

        foreach (str_split($secret) as $char) {
            $position = strpos(self::ALPHABET, $char);

            if ($position === false) {
                continue;
            }

            $binary .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
