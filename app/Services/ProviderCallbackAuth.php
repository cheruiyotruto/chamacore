<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Authenticates provider webhooks that cannot present a Sanctum token.
 *
 * The shared secret is mandatory outside local/testing. Providers that can set
 * headers send X-Callback-Secret or X-Callback-Signature (HMAC-SHA256 of the
 * raw request body); providers that cannot set headers can include
 * callback_secret in the configured callback URL query string.
 */
class ProviderCallbackAuth
{
    /**
     * @return string 'ok', 'misconfigured' or 'rejected'
     */
    public function check(Request $request, string $secret, string $channel): string
    {
        if ($secret === '') {
            if (app()->environment(['local', 'testing'])) {
                return 'ok';
            }

            Log::error('callback.secret.missing', ['channel' => $channel]);

            return 'misconfigured';
        }

        $provided = (string) ($request->header('X-Callback-Secret')
            ?: $request->query('callback_secret', '')
            ?: '');

        $signature = (string) $request->header('X-Callback-Signature', '');

        $validSecret = $provided !== '' && hash_equals($secret, $provided);
        $validSignature = $signature !== ''
            && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);

        if (! $validSecret && ! $validSignature) {
            Log::warning('callback.rejected', [
                'channel' => $channel,
                'ip' => $request->ip(),
            ]);

            return 'rejected';
        }

        return 'ok';
    }
}
