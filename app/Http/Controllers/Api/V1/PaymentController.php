<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $intents = PaymentIntent::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate((int) min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $intents->through(fn (PaymentIntent $intent) => $this->payload($intent)),
        ]);
    }

    public function show(PaymentIntent $intent): JsonResponse
    {
        abort_unless($intent->member_id === $this->membership()->id, 403);

        return response()->json(['payment' => $this->payload($intent)]);
    }

    public function store(Request $request, PaymentService $payments): JsonResponse
    {
        $member = $this->membership();

        $data = $request->validate([
            'purpose' => ['required', 'in:wallet_topup,savings_deposit,loan_repayment,bill_payment'],
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
            'savings_account_id' => ['nullable', 'integer'],
            'loan_id' => ['nullable', 'integer'],
            'bill_id' => ['nullable', 'integer'],
            'manual_charge' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($data['purpose'] === 'savings_deposit') {
            $account = \App\Models\SavingsAccount::query()
                ->where('member_id', $member->id)
                ->when($data['savings_account_id'] ?? null, fn ($query, $id) => $query->where('id', $id))
                ->first();

            abort_unless($account, 422, 'A savings account is required for this payment.');
        }

        if ($data['purpose'] === 'loan_repayment') {
            $loan = \App\Models\Loan::query()
                ->where('member_id', $member->id)
                ->when($data['loan_id'] ?? null, fn ($query, $id) => $query->where('id', $id))
                ->whereIn('status', ['active', 'disbursed', 'restructured'])
                ->first();

            abort_unless($loan, 422, 'An active loan is required for this payment.');
        }

        if ($data['purpose'] === 'bill_payment') {
            $bill = \App\Models\Bill::query()
                ->where('member_id', $member->id)
                ->when($data['bill_id'] ?? null, fn ($query, $id) => $query->where('id', $id))
                ->whereIn('status', ['pending', 'partially_paid', 'overdue'])
                ->first();

            abort_unless($bill, 422, 'An unpaid bill is required for this payment.');
        }

        try {
            $intent = $payments->initiate($member, [
                'purpose' => $data['purpose'],
                'amount' => (int) round(((float) $data['amount']) * 100),
                'phone' => $data['phone'],
                'payload' => array_filter([
                    'savings_account_id' => $data['savings_account_id'] ?? null,
                    'loan_id' => $data['loan_id'] ?? null,
                    'bill_id' => $data['bill_id'] ?? null,
                    'manual_charge' => isset($data['manual_charge'])
                        ? (int) round(((float) $data['manual_charge']) * 100)
                        : null,
                ], fn ($value) => $value !== null),
            ], 'api-payment-'.Str::uuid());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Payment request sent. Awaiting provider confirmation.',
            'payment' => $this->payload($intent),
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PaymentIntent $intent): array
    {
        return [
            'uuid' => $intent->uuid,
            'purpose' => $intent->purpose,
            'amount' => $intent->amount,
            'provider' => $intent->provider,
            'phone' => $intent->phone,
            'status' => $intent->status,
            'provider_reference' => $intent->provider_reference,
            'base_amount' => (int) (data_get($intent->payload, 'base_amount') ?? $intent->amount),
            'charge_amount' => (int) (data_get($intent->payload, 'charge_quote.total_fee') ?? 0),
            'total_amount' => $intent->amount,
            'bill_id' => data_get($intent->payload, 'bill_id'),
            'failure_reason' => $intent->failure_reason,
            'created_at' => $intent->created_at?->toIso8601String(),
            'completed_at' => $intent->completed_at?->toIso8601String(),
        ];
    }
}
