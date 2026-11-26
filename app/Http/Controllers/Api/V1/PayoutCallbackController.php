<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\PayoutService;
use App\Services\ProviderCallbackAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Provider webhook for bank transfers and M-Pesa B2B/B2C. A configured shared
 * secret (or HMAC) is required in every deployed environment; service-level
 * idempotency prevents duplicate settlement.
 */
class PayoutCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        PayoutService $payouts,
        ProviderCallbackAuth $auth
    ): JsonResponse {
        if (! in_array($provider, ['bank', 'mpesa'], true)) {
            return response()->json(['message' => 'Unknown provider.'], 404);
        }

        $secret = (string) ($provider === 'bank'
            ? config('services.bank_payouts.callback_secret')
            : config('services.mpesa.b2b_callback_secret'));

        $status = $auth->check($request, $secret, 'payout.'.$provider);

        if ($status !== 'ok') {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Rejected'], $status === 'misconfigured' ? 503 : 403);
        }

        try {
            $result = $payouts->handleCallback($provider, $request->all());
        } catch (BusinessRuleException $e) {
            Log::warning('payout.callback.failed', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted',
                'status' => 'ignored',
            ]);
        }

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
            'status' => $result['status'],
        ]);
    }
}
