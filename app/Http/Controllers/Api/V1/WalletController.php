<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->ensureWallet($this->membership());

        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->paginate((int) min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'wallet' => [
                'id' => $wallet->id,
                'balance' => $wallet->balance,
                'available_balance' => $wallet->available_balance,
                'status' => $wallet->status,
            ],
            'transactions' => $transactions->through(fn (WalletTransaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'direction' => $transaction->direction,
                'amount' => $transaction->amount,
                'balance_after' => $transaction->balance_after,
                'description' => $transaction->description,
                'reference' => $transaction->reference,
                'status' => $transaction->status,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function topUp(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        try {
            $intent = $payments->initiate($this->membership(), [
                'purpose' => 'wallet_topup',
                'amount' => (int) round(((float) $data['amount']) * 100),
                'phone' => $data['phone'],
            ], 'wallet-topup-'.Str::uuid());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Approve the M-Pesa request on your phone to complete the top-up.',
            'payment' => [
                'uuid' => $intent->uuid,
                'status' => $intent->status,
                'amount' => $intent->amount,
                'provider_reference' => $intent->provider_reference,
            ],
        ], 202);
    }

    public function transfer(Request $request): JsonResponse
    {
        $member = $this->membership();

        $data = $request->validate([
            'to_member_id' => ['required', 'integer', 'different:'.$member->id],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $recipient = Member::query()->active()->findOrFail($data['to_member_id']);
        $this->wallets->ensureWallet($recipient);

        try {
            $result = $this->wallets->transfer(
                $member,
                $recipient,
                (int) round(((float) $data['amount']) * 100),
                'PTR-'.strtoupper(Str::random(8)),
                'api-transfer-'.Str::uuid()
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Transfer completed.',
            'transfer' => [
                'amount' => $result['debit']->amount,
                'reference' => $result['debit']->reference,
                'transaction_id' => $result['debit']->id,
            ],
        ]);
    }

    public function members(): JsonResponse
    {
        return response()->json([
            'data' => Member::query()
                ->active()
                ->where('id', '!=', $this->membership()->id)
                ->orderBy('first_name')
                ->get(['id', 'member_no', 'first_name', 'middle_name', 'last_name'])
                ->map(fn (Member $member) => [
                    'id' => $member->id,
                    'member_no' => $member->member_no,
                    'name' => $member->full_name,
                ])->values(),
        ]);
    }

    public function payments(): JsonResponse
    {
        $intents = PaymentIntent::query()
            ->where('purpose', 'wallet_topup')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $intents->map(fn (PaymentIntent $intent) => [
                'uuid' => $intent->uuid,
                'status' => $intent->status,
                'amount' => $intent->amount,
                'provider_reference' => $intent->provider_reference,
                'created_at' => $intent->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
