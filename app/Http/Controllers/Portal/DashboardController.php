<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Fine;
use App\Models\Guarantor;
use App\Models\Investment;
use App\Models\InvestmentTransaction;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\MerryGoRoundParticipant;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\InvestmentService;
use App\Support\PortalAccess;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly InvestmentService $investments)
    {
    }

    public function index(): View
    {
        $member = $this->membership();

        $savingsAccounts = SavingsAccount::query()->with('product')->get();
        $loans = Loan::query()->with('product')->orderByDesc('created_at')->get();
        $wallet = Wallet::query()->where('member_id', $member->id)->first();

        $this->investments->processDueForMember($member);

        $memberInvestments = Investment::query()
            ->with('product')
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->get();
        $investmentSummary = $this->investments->summaryFor($member);

        $loanIds = $loans->pluck('id');

        $upcoming = LoanSchedule::query()
            ->with('loan')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->limit(6)
            ->get();

        $participations = MerryGoRoundParticipant::query()
            ->with(['cycle'])
            ->where('member_id', $member->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $fines = Fine::query()
            ->whereIn('status', ['pending', 'partially_paid'])
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        $guaranteeRequests = Guarantor::query()
            ->with('loan')
            ->where('guarantor_member_id', $member->id)
            ->where('status', Guarantor::STATUS_PENDING)
            ->get();

        $savingsTotal = (int) $savingsAccounts->sum('balance');
        $loanBalance = (int) $loans->filter(fn (Loan $loan) => $loan->isActive())->sum(fn (Loan $loan) => $loan->outstandingTotal());

        $recentTransactions = collect()
            ->merge(SavingsTransaction::query()->with('account.product')->latest('created_at')->limit(5)->get()->map(fn ($transaction) => [
                'date' => $transaction->transaction_date?->toDateString(),
                'label' => ucfirst(str_replace('_', ' ', $transaction->type)).' · '.($transaction->account?->product?->name ?? 'Savings'),
                'direction' => $transaction->direction,
                'amount' => $transaction->amount,
            ]))
            ->merge(WalletTransaction::query()->latest('created_at')->limit(5)->get()->map(fn ($transaction) => [
                'date' => $transaction->created_at?->toDateString(),
                'label' => $transaction->description ?: ucfirst(str_replace('_', ' ', $transaction->type)),
                'direction' => $transaction->direction,
                'amount' => $transaction->amount,
            ]))
            ->merge(InvestmentTransaction::query()
                ->with('investment.product')
                ->where('member_id', $member->id)
                ->latest('transaction_date')
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn ($transaction) => [
                    'date' => $transaction->transaction_date?->toDateString(),
                    'label' => ucfirst(str_replace('_', ' ', $transaction->type)).' · '.($transaction->investment?->product?->name ?? 'Investment'),
                    'direction' => $transaction->direction,
                    'amount' => $transaction->amount,
                ]))
            ->sortByDesc('date')
            ->take(8)
            ->values();

        return view('portal.dashboard', [
            'member' => $member,
            'documentsEnabled' => PortalAccess::canAccessSection($this->group(), $member, 'documents'),
            'savingsAccounts' => $savingsAccounts,
            'savingsTotal' => $savingsTotal,
            'loans' => $loans,
            'loanBalance' => $loanBalance,
            'wallet' => $wallet,
            'upcoming' => $upcoming,
            'participations' => $participations,
            'fines' => $fines,
            'guaranteeRequests' => $guaranteeRequests,
            'recentTransactions' => $recentTransactions,
            'memberInvestments' => $memberInvestments,
            'investmentSummary' => $investmentSummary,
        ]);
    }
}
