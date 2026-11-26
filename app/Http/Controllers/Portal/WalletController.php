<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function __construct(private readonly WalletService $wallets)
    {
    }

    public function show(): View
    {
        $member = $this->membership();
        $wallet = $this->wallets->ensureWallet($member);

        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('portal.wallet.show', [
            'wallet' => $wallet,
            'transactions' => $transactions,
            'members' => Member::query()
                ->active()
                ->where('id', '!=', $member->id)
                ->orderBy('first_name')
                ->get(),
            'topUps' => PaymentIntent::query()
                ->where('purpose', 'wallet_topup')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function topUp(Request $request, PaymentService $payments): RedirectResponse
    {
        $member = $this->membership();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        try {
            $intent = $payments->initiate($member, [
                'purpose' => 'wallet_topup',
                'amount' => (int) round(((float) $data['amount']) * 100),
                'phone' => $data['phone'],
            ], 'topup-'.Str::uuid());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Approve the M-Pesa request on your phone to complete the top-up. Reference: '.$intent->uuid);
    }

    public function transfer(Request $request): RedirectResponse
    {
        $member = $this->membership();

        $data = $request->validate([
            'to_member_id' => ['required', 'integer', 'different:'.$member->id],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $recipient = Member::query()->active()->findOrFail($data['to_member_id']);

        $this->wallets->ensureWallet($recipient);

        try {
            $this->wallets->transfer(
                $member,
                $recipient,
                (int) round(((float) $data['amount']) * 100),
                'PTR-'.strtoupper(Str::random(8)),
                'portal-transfer-'.Str::uuid()
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Transfer to '.$recipient->full_name.' completed.');
    }
}
