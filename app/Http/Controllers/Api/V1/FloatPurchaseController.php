<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\FloatPurchase;
use App\Models\MpesaAgent;
use App\Services\FloatPurchaseService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FloatPurchaseController extends Controller
{
    public function __construct(private readonly FloatPurchaseService $floats)
    {
    }

    public function packages(): JsonResponse
    {
        return response()->json(['data' => $this->floats->packages()]);
    }

    public function agents(): JsonResponse
    {
        $agents = MpesaAgent::withoutGlobalScopes()
            ->where('group_id', $this->group()->id)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (MpesaAgent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'label' => $agent->label(),
                'agent_number' => $agent->agent_number,
                'store_number' => $agent->store_number,
                'phone' => $agent->phone,
            ]);

        return response()->json(['data' => $agents]);
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:1']]);

        return response()->json([
            'quote' => $this->floats->quote($this->group(), Money::toMinor($data['amount'])),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $purchases = FloatPurchase::query()
            ->where('member_id', $this->membership()->id)
            ->orderByDesc('created_at')
            ->paginate((int) min(max($request->integer('per_page', 25), 1), 100));

        return response()->json($purchases->through(fn (FloatPurchase $purchase) => [
            'uuid' => $purchase->uuid,
            'reference' => $purchase->reference,
            'network' => $purchase->network,
            'phone' => $purchase->phone,
            'agent_id' => $purchase->agent_id,
            'agent_number' => $purchase->agent_number,
            'store_number' => $purchase->store_number,
            'agent_name' => $purchase->agent_name,
            'amount' => $purchase->amount,
            'charge_amount' => $purchase->charge_amount,
            'total_amount' => $purchase->total_amount,
            'currency' => $purchase->currency,
            'status' => $purchase->status,
            'provider_reference' => $purchase->provider_reference,
            'failure_reason' => $purchase->failure_reason,
            'created_at' => $purchase->created_at?->toIso8601String(),
            'completed_at' => $purchase->completed_at?->toIso8601String(),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'agent_id' => ['nullable', 'integer'],
            'agent_name' => ['nullable', 'string', 'max:120'],
            'agent_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{4,15}$/', 'required_without:agent_id'],
            'store_number' => ['nullable', 'string', 'max:30', 'regex:/^\d{4,15}$/', 'required_without:agent_id'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:agent_id'],
            'network' => ['sometimes', 'string', 'in:mpesa'],
            'idempotency_key' => ['sometimes', 'string', 'max:120'],
        ]);

        try {
            $purchase = $this->floats->purchase($this->group(), $this->membership(), $this->actor(), [
                'amount' => Money::toMinor($data['amount']),
                'agent_id' => $data['agent_id'] ?? null,
                'agent_name' => $data['agent_name'] ?? null,
                'agent_number' => $data['agent_number'] ?? null,
                'store_number' => $data['store_number'] ?? null,
                'phone' => $data['phone'] ?? '',
                'network' => $data['network'] ?? 'mpesa',
                'idempotency_key' => $data['idempotency_key'] ?: 'api-'.(string) Str::uuid(),
            ]);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $this->message($purchase->status, $purchase->reference),
            'purchase' => [
                'uuid' => $purchase->uuid,
                'reference' => $purchase->reference,
                'status' => $purchase->status,
                'amount' => $purchase->amount,
                'charge_amount' => $purchase->charge_amount,
                'total_amount' => $purchase->total_amount,
                'currency' => $purchase->currency,
                'phone' => $purchase->phone,
                'agent_number' => $purchase->agent_number,
                'store_number' => $purchase->store_number,
                'provider_reference' => $purchase->provider_reference,
            ],
        ], $purchase->status === FloatPurchase::STATUS_COMPLETED ? 201 : 202);
    }

    private function message(string $status, string $reference): string
    {
        return match ($status) {
            FloatPurchase::STATUS_COMPLETED => 'M-Pesa float delivered successfully. Reference: '.$reference,
            FloatPurchase::STATUS_PROCESSING => 'Your float purchase is being processed. Reference: '.$reference,
            FloatPurchase::STATUS_FAILED => 'The float purchase failed and your wallet was refunded. Reference: '.$reference,
            default => 'Float purchase queued for fulfilment. Reference: '.$reference,
        };
    }
}
