<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Fine;
use App\Models\Guarantor;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\MerryGoRoundParticipant;
use App\Models\SavingsAccount;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $membership = $this->membership();

        $accounts = SavingsAccount::query()->with('product')->get();
        $loans = Loan::query()->with('product')->get();
        $wallet = Wallet::query()->where('member_id', $membership->id)->first();

        $loanIds = $loans->pluck('id');

        $nextDue = LoanSchedule::query()
            ->with('loan:id,loan_no')
            ->whereIn('loan_id', $loanIds)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->limit(5)
            ->get()
            ->map(fn (LoanSchedule $schedule) => [
                'loan_id' => $schedule->loan_id,
                'loan_no' => $schedule->loan?->loan_no,
                'due_date' => $schedule->due_date?->toDateString(),
                'amount_due' => max(0, $schedule->totalDue() - $schedule->totalPaid()),
            ]);

        $activeLoans = $loans->filter(fn (Loan $loan) => $loan->isActive());

        return response()->json([
            'member' => [
                'id' => $membership->id,
                'name' => $membership->full_name,
                'member_no' => $membership->member_no,
            ],
            'summary' => [
                'savings_balance' => (int) $accounts->sum('balance'),
                'loan_outstanding' => (int) $activeLoans->sum(fn (Loan $loan) => $loan->outstandingTotal()),
                'active_loans' => $activeLoans->count(),
                'wallet_balance' => (int) ($wallet?->balance ?? 0),
                'pending_fines' => (int) Fine::query()
                    ->whereIn('status', ['pending', 'partially_paid'])
                    ->sum('amount'),
                'mgr_active_cycles' => MerryGoRoundParticipant::query()
                    ->where('member_id', $membership->id)
                    ->where('status', 'active')
                    ->count(),
            ],
            'savings_accounts' => $accounts->map(fn (SavingsAccount $account) => [
                'id' => $account->id,
                'account_no' => $account->account_no,
                'product' => $account->product?->name,
                'balance' => $account->balance,
                'available_balance' => $account->available_balance,
                'pledged_amount' => $account->pledged_amount,
            ])->values(),
            'loans' => $loans->take(5)->map(fn (Loan $loan) => [
                'id' => $loan->id,
                'loan_no' => $loan->loan_no,
                'status' => $loan->status,
                'product' => $loan->product?->name,
                'outstanding' => $loan->outstandingTotal(),
                'progress_percent' => $loan->progressPercent(),
            ])->values(),
            'next_installments' => $nextDue,
            'guarantee_requests' => Guarantor::query()
                ->with('loan:id,loan_no,requested_amount')
                ->where('guarantor_member_id', $membership->id)
                ->where('status', Guarantor::STATUS_PENDING)
                ->get()
                ->map(fn (Guarantor $guarantor) => [
                    'id' => $guarantor->id,
                    'loan_id' => $guarantor->loan_id,
                    'loan_no' => $guarantor->loan?->loan_no,
                    'amount' => $guarantor->guaranteed_amount,
                    'requested_at' => $guarantor->requested_at?->toIso8601String(),
                ])->values(),
        ]);
    }
}
