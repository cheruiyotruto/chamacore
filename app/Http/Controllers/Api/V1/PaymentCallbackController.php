<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\PaymentService;
use App\Services\ProviderCallbackAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Daraja STK push callbacks. Safaricom cannot present a Sanctum
 * token, so the endpoint requires a configured callback secret (or HMAC) in
 * every deployed environment, plus idempotency inside PaymentService.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentService $payments,
        ProviderCallbackAuth $auth
    ): JsonResponse {
        $status = $auth->check(
            $request,
            (string) config('services.mpesa.callback_secret'),
            'mpesa.stk'
        );

        if ($status !== 'ok') {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Rejected'], $status === 'misconfigured' ? 503 : 403);
        }

        try {
            $result = $payments->handleMpesaCallback($request->all());
        } catch (BusinessRuleException $e) {
            Log::warning('mpesa.callback.failed', ['message' => $e->getMessage()]);

            // Acknowledge malformed or unknown callbacks so Daraja does not
            // retry forever; the failure is logged and visible to operators.
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
