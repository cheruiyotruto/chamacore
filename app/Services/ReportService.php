<?php

namespace App\Services;

use App\Models\Group;
use App\Models\LedgerAccount;
use App\Models\LedgerLine;
use App\Models\Loan;
use App\Models\LoanSchedule;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\WalletTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only financial reporting. All figures are derived from posted journals
 * and stored balances; nothing here mutates financial state.
 */
class ReportService
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    /**
     * @return array<int, array{account:LedgerAccount, debit:int, credit:int, balance:int}>
     */
    public function trialBalance(Group $group, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return $this->ledger->trialBalance($group, $from, $to);
    }

    /**
     * @return array{income:array<int,array{account:LedgerAccount,amount:int}>,expense:array<int,array{account:LedgerAccount,amount:int}>,total_income:int,total_expense:int,net:int}
     */
    public function incomeStatement(Group $group, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->periodMovements($group, $from, $to);

        $income = [];
        $expense = [];
        $totalIncome = 0;
        $totalExpense = 0;

        foreach ($rows as $row) {
            /** @var LedgerAccount $account */
            $account = $row['account'];

            if ($account->type === LedgerAccount::TYPE_INCOME) {
                $amount = $row['credit'] - $row['debit'];
                $income[] = ['account' => $account, 'amount' => $amount];
                $totalIncome += $amount;
            } elseif ($account->type === LedgerAccount::TYPE_EXPENSE) {
                $amount = $row['debit'] - $row['credit'];
                $expense[] = ['account' => $account, 'amount' => $amount];
                $totalExpense += $amount;
            }
        }

        return [
            'income' => $income,
            'expense' => $expense,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net' => $totalIncome - $totalExpense,
        ];
    }

    /**
     * @return array{assets:array<int,array{account:LedgerAccount,amount:int}>,liabilities:array<int,array{account:LedgerAccount,amount:int}>,equity:array<int,array{account:LedgerAccount,amount:int}>,total_assets:int,total_liabilities:int,total_equity:int,net_income:int,balanced:bool}
     */
    public function balanceSheet(Group $group, ?CarbonInterface $to = null): array
    {
        $rows = $this->trialBalance($group, null, $to);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquity = 0;

        foreach ($rows as $row) {
            /** @var LedgerAccount $account */
            $account = $row['account'];
            $amount = $row['balance'];

            if ($account->type === LedgerAccount::TYPE_ASSET) {
                $assets[] = ['account' => $account, 'amount' => $amount];
                $totalAssets += $amount;
            } elseif ($account->type === LedgerAccount::TYPE_LIABILITY) {
                $liabilities[] = ['account' => $account, 'amount' => $amount];
                $totalLiabilities += $amount;
            } elseif ($account->type === LedgerAccount::TYPE_EQUITY) {
                $equity[] = ['account' => $account, 'amount' => $amount];
                $totalEquity += $amount;
            }
        }

        $start = now()->startOfYear();
        $income = $this->incomeStatement($group, $start, $to ?? now());
        $netIncome = $income['net'];

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'net_income' => $netIncome,
            'balanced' => $totalAssets === $totalLiabilities + $totalEquity + $netIncome,
        ];
    }

    /**
     * @return array{accounts:Collection<int,SavingsAccount>,total:int,pledged:int,by_product:array<string,int>}
     */
    public function savingsSummary(Group $group): array
    {
        $accounts = SavingsAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('status', 'active')
            ->with(['member', 'product'])
            ->get();

        $byProduct = [];
        foreach ($accounts as $account) {
            $name = $account->product?->name ?? 'Unassigned';
            $byProduct[$name] = ($byProduct[$name] ?? 0) + $account->balance;
        }

        return [
            'accounts' => $accounts,
            'total' => (int) $accounts->sum('balance'),
            'pledged' => (int) $accounts->sum('pledged_amount'),
            'by_product' => $byProduct,
        ];
    }

    /**
     * @return array{loans:Collection<int,Loan>,principal:int,outstanding:int,interest:int,penalties:int,arrears:int,by_status:array<string,array{count:int,outstanding:int}>}
     */
    public function loanPortfolio(Group $group): array
    {
        $loans = Loan::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->with(['member', 'product'])
            ->orderByDesc('created_at')
            ->get();

        $byStatus = [];

        foreach ($loans as $loan) {
            $key = $loan->status;
            $byStatus[$key] ??= ['count' => 0, 'outstanding' => 0];
            $byStatus[$key]['count']++;
            $byStatus[$key]['outstanding'] += $loan->outstandingTotal();
        }

        $active = $loans->filter(fn (Loan $loan) => $loan->isActive());

        return [
            'loans' => $loans,
            'principal' => (int) $active->sum('principal'),
            'outstanding' => (int) $active->sum(fn (Loan $loan) => $loan->outstandingTotal()),
            'interest' => (int) $active->sum('outstanding_interest'),
            'penalties' => (int) $active->sum('outstanding_penalties'),
            'arrears' => (int) $loans->sum(fn (Loan $loan) => $loan->overdueAmount()),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return array{cycles:Collection<int,\App\Models\MerryGoRoundCycle>,collected:int,pending:int,payouts:int}
     */
    public function merryGoRoundSummary(Group $group): array
    {
        $cycles = \App\Models\MerryGoRoundCycle::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->with(['participants.member', 'payouts'])
            ->orderByDesc('start_date')
            ->get();

        $collected = 0;
        $pending = 0;
        $payouts = 0;

        foreach ($cycles as $cycle) {
            $collected += (int) $cycle->contributions()->where('status', 'paid')->sum('amount');
            $pending += (int) $cycle->contributions()->whereIn('status', ['pending', 'late', 'missed'])->sum('amount');
            $payouts += (int) $cycle->payouts()->where('status', 'paid')->sum('amount');
        }

        return [
            'cycles' => $cycles,
            'collected' => $collected,
            'pending' => $pending,
            'payouts' => $payouts,
        ];
    }

    /**
     * A consolidated member statement across savings, wallet and loan activity.
     *
     * @return array<int, array{date:string,type:string,description:string,debit:int,credit:int,reference:?string}>
     */
    public function memberStatement(Member $member, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = [];

        $savings = SavingsTransaction::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->with('account.product')
            ->get();

        foreach ($savings as $transaction) {
            $rows[] = [
                'date' => $transaction->transaction_date->toDateString(),
                'type' => 'Savings',
                'description' => ucfirst(str_replace('_', ' ', $transaction->type)).' · '.($transaction->account?->product?->name ?? 'Savings'),
                'debit' => $transaction->direction === 'debit' ? $transaction->amount : 0,
                'credit' => $transaction->direction === 'credit' ? $transaction->amount : 0,
                'reference' => $transaction->reference,
            ];
        }

        $wallet = WalletTransaction::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->get();

        foreach ($wallet as $transaction) {
            $rows[] = [
                'date' => $transaction->created_at?->toDateString() ?? '',
                'type' => 'Wallet',
                'description' => $transaction->description ?: ucfirst(str_replace('_', ' ', $transaction->type)),
                'debit' => $transaction->direction === 'debit' ? $transaction->amount : 0,
                'credit' => $transaction->direction === 'credit' ? $transaction->amount : 0,
                'reference' => $transaction->reference,
            ];
        }

        $payments = \App\Models\LoanPayment::withoutGlobalScopes()
            ->where('group_id', $member->group_id)
            ->where('member_id', $member->id)
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->with('loan')
            ->get();

        foreach ($payments as $payment) {
            $rows[] = [
                'date' => $payment->paid_at?->toDateString() ?? '',
                'type' => 'Loan repayment',
                'description' => 'Repayment for '.($payment->loan?->loan_no ?? 'loan'),
                'debit' => $payment->amount,
                'credit' => 0,
                'reference' => $payment->reference,
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    /**
     * @return array<int, array{account:LedgerAccount,debit:int,credit:int,balance:int}>
     */
    private function periodMovements(Group $group, CarbonInterface $from, CarbonInterface $to): array
    {
        $accounts = LedgerAccount::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->orderBy('code')
            ->get();

        $totals = LedgerLine::withoutGlobalScopes()
            ->selectRaw('ledger_account_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->where('group_id', $group->id)
            ->whereHas('journalEntry', fn ($query) => $query
                ->whereDate('entry_date', '>=', $from->toDateString())
                ->whereDate('entry_date', '<=', $to->toDateString()))
            ->groupBy('ledger_account_id')
            ->get()
            ->keyBy('ledger_account_id');

        return $accounts->map(function (LedgerAccount $account) use ($totals) {
            $row = $totals->get($account->id);

            return [
                'account' => $account,
                'debit' => (int) ($row->total_debit ?? 0),
                'credit' => (int) ($row->total_credit ?? 0),
                'balance' => 0,
            ];
        })->all();
    }
}
