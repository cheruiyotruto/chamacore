<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\FloatPurchaseService;
use App\Services\ProviderCallbackAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Signed webhook for airtime/float providers. Duplicate callbacks are safe:
 * service-level guards ignore purchases that already reached a final state.
 */
class FloatPurchaseCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        FloatPurchaseService $floats,
        ProviderCallbackAuth $auth
    ): JsonResponse {
        $status = $auth->check(
            $request,
            (string) config('services.float.callback_secret'),
            'float.purchase'
        );

        if ($status !== 'ok') {
            return response()->json(
                ['message' => $status === 'misconfigured' ? 'Callback not configured.' : 'Rejected.'],
                $status === 'misconfigured' ? 503 : 403
            );
        }

        try {
            $purchase = $floats->handleCallback($request->all());
        } catch (BusinessRuleException $e) {
            Log::warning('float.callback.failed', ['message' => $e->getMessage()]);

            return response()->json(['message' => 'Accepted', 'status' => 'ignored']);
        }

        return response()->json([
            'message' => 'Accepted',
            'status' => $purchase->status,
            'reference' => $purchase->reference,
        ]);
    }
}
