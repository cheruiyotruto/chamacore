<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SavingsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SavingsAccount::class);

        $accounts = SavingsAccount::query()->with('product')->get();

        return response()->json([
            'data' => $accounts->map(fn (SavingsAccount $account) => $this->accountPayload($account))->values(),
            'totals' => [
                'balance' => (int) $accounts->sum('balance'),
                'available' => (int) $accounts->sum('available_balance'),
                'pledged' => (int) $accounts->sum('pledged_amount'),
            ],
        ]);
    }

    public function show(Request $request, SavingsAccount $account): JsonResponse
    {
        $this->authorize('view', $account);

        $transactions = $account->transactions()
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate((int) min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'account' => $this->accountPayload($account->load('product')),
            'transactions' => $transactions->through(fn (SavingsTransaction $transaction) => [
                'id' => $transaction->id,
                'date' => $transaction->transaction_date?->toDateString(),
                'type' => $transaction->type,
                'direction' => $transaction->direction,
                'amount' => $transaction->amount,
                'balance_after' => $transaction->balance_after,
                'description' => $transaction->description,
                'reference' => $transaction->reference,
            ]),
        ]);
    }

    public function deposit(Request $request, SavingsAccount $account, PaymentService $payments): JsonResponse
    {
        $this->authorize('view', $account);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        try {
            $intent = $payments->initiate($this->membership(), [
                'purpose' => 'savings_deposit',
                'amount' => (int) round(((float) $data['amount']) * 100),
                'phone' => $data['phone'],
                'payload' => ['savings_account_id' => $account->id],
            ], 'savings-'.Str::uuid());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Approve the M-Pesa request on your phone to complete the deposit.',
            'payment' => [
                'uuid' => $intent->uuid,
                'status' => $intent->status,
                'amount' => $intent->amount,
                'provider_reference' => $intent->provider_reference,
            ],
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPayload(SavingsAccount $account): array
    {
        return [
            'id' => $account->id,
            'account_no' => $account->account_no,
            'product' => $account->product?->name,
            'type' => $account->product?->type,
            'balance' => $account->balance,
            'available_balance' => $account->available_balance,
            'pledged_amount' => $account->pledged_amount,
            'status' => $account->status,
            'opened_at' => $account->opened_at?->toIso8601String(),
        ];
    }
}
