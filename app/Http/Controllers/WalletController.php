<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Member;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\AuditService;
use App\Services\LedgerService;
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

    public function index(Request $request): View
    {
        $this->requirePermission('wallet.view');

        $wallets = Wallet::query()
            ->with('member')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->whereHas('member', fn ($inner) => $inner
                    ->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('member_no', 'like', $search)
                    ->orWhere('phone', 'like', $search));
            })
            ->orderByDesc('balance')
            ->paginate(25)
            ->withQueryString();

        return view('wallet.index', [
            'wallets' => $wallets,
            'totalBalance' => (int) Wallet::sum('balance'),
            'totalAvailable' => (int) Wallet::sum('available_balance'),
            'members' => Member::query()->active()->orderBy('first_name')->get(),
        ]);
    }

    public function show(Wallet $wallet): View
    {
        $this->requirePermission('wallet.view');

        $wallet->load('member');

        $transactions = $wallet->transactions()
            ->with('journalEntry')
            ->orderByDesc('created_at')
            ->paginate(30);

        return view('wallet.show', [
            'wallet' => $wallet,
            'transactions' => $transactions,
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $this->requirePermission('wallet.adjust');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'counter' => ['required', 'in:cash,bank'],
            'description' => ['required', 'string', 'max:200'],
        ]);

        $member = Member::query()->findOrFail($data['member_id']);
        $amount = (int) round(((float) $data['amount']) * 100);
        $counter = $data['counter'] === 'cash' ? LedgerService::CASH : LedgerService::BANK;
        $reference = 'WALLET-'.strtoupper(Str::random(8));

        try {
            if ($data['direction'] === 'credit') {
                $this->wallets->credit(
                    $member,
                    $amount,
                    'deposit',
                    $counter,
                    $reference,
                    'wallet-adjust-'.Str::uuid(),
                    $data['description']
                );
            } else {
                $this->wallets->debit(
                    $member,
                    $amount,
                    'withdrawal',
                    $counter,
                    $reference,
                    'wallet-adjust-'.Str::uuid(),
                    $data['description']
                );
            }
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', ucfirst($data['direction']).' of '.$this->formatAmount($amount).' recorded for '.$member->full_name.'.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $this->requirePermission('wallet.transfer');

        $data = $request->validate([
            'from_member_id' => ['required', 'integer'],
            'to_member_id' => ['required', 'integer', 'different:from_member_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $from = Member::query()->findOrFail($data['from_member_id']);
        $to = Member::query()->findOrFail($data['to_member_id']);
        $amount = (int) round(((float) $data['amount']) * 100);

        $this->wallets->ensureWallet($from);
        $this->wallets->ensureWallet($to);

        try {
            $this->wallets->transfer($from, $to, $amount, 'WALLET-TRF-'.strtoupper(Str::random(8)));
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Transferred '.$this->formatAmount($amount).' from '.$from->full_name.' to '.$to->full_name.'.');
    }

    public function reconcile(): View
    {
        $this->requirePermission('wallet.reconcile');

        $issues = $this->wallets->reconcile($this->group());

        return view('wallet.reconcile', [
            'issues' => $issues,
            'ledgerBalance' => app(LedgerService::class)->accountBalance($this->group(), LedgerService::WALLET_LIABILITY),
            'walletTotal' => (int) Wallet::sum('balance'),
        ]);
    }

    public function markReconciled(Request $request): RedirectResponse
    {
        $this->requirePermission('wallet.reconcile');

        $issues = $this->wallets->reconcile($this->group());

        AuditService::log('wallet.reconciled', [
            'issues' => count($issues),
            'checked_at' => now()->toIso8601String(),
        ], $this->group());

        if ($issues !== []) {
            return back()->with('error', 'Reconciliation found '.count($issues).' issue(s). Resolve them before signing off.');
        }

        return back()->with('status', 'Wallet ledger reconciles. Sign-off recorded.');
    }

    private function formatAmount(int $cents): string
    {
        return $this->group()->currency.' '.number_format($cents / 100, 2);
    }
}
