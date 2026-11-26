<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(): View
    {
        $this->requirePermission('reports.view');

        return view('reports.index');
    }

    public function trialBalance(Request $request): View
    {
        $this->requirePermission('reports.view');

        [$from, $to] = $this->period($request);

        return view('reports.trial-balance', [
            'rows' => $this->reports->trialBalance($this->group(), $from, $to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function incomeStatement(Request $request): View
    {
        $this->requirePermission('reports.view');

        [$from, $to] = $this->period($request);

        return view('reports.income-statement', [
            'report' => $this->reports->incomeStatement($this->group(), $from, $to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function balanceSheet(Request $request): View
    {
        $this->requirePermission('reports.view');

        $to = $request->filled('to') ? CarbonImmutable::parse($request->date('to')) : CarbonImmutable::now();

        return view('reports.balance-sheet', [
            'report' => $this->reports->balanceSheet($this->group(), $to),
            'to' => $to->toDateString(),
        ]);
    }

    public function savings(): View
    {
        $this->requirePermission('reports.view');

        return view('reports.savings', ['report' => $this->reports->savingsSummary($this->group())]);
    }

    public function loans(): View
    {
        $this->requirePermission('reports.view');

        return view('reports.loans', ['report' => $this->reports->loanPortfolio($this->group())]);
    }

    public function merryGoRound(): View
    {
        $this->requirePermission('reports.view');

        return view('reports.mgr', ['report' => $this->reports->merryGoRoundSummary($this->group())]);
    }

    public function memberStatement(Request $request, ?Member $member = null): View
    {
        $this->requirePermission('reports.view');

        [$from, $to] = $this->period($request);
        $member ??= $request->filled('member_id')
            ? Member::query()->find($request->integer('member_id'))
            : Member::query()->active()->orderBy('first_name')->first();

        return view('reports.member-statement', [
            'member' => $member,
            'rows' => $member ? $this->reports->memberStatement($member, $from, $to) : [],
            'members' => Member::query()->active()->orderBy('first_name')->get(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
    }

    public function export(Request $request)
    {
        $this->requirePermission('reports.export');

        $type = $request->validate(['type' => ['required', 'in:trial_balance,income_statement,savings,loans,mgr,member_statement']])['type'];
        [$from, $to] = $this->period($request);

        return match ($type) {
            'trial_balance' => $this->exportTrialBalance($from, $to),
            'income_statement' => $this->exportIncomeStatement($from, $to),
            'savings' => $this->exportSavings(),
            'loans' => $this->exportLoans(),
            'mgr' => $this->exportMgr(),
            'member_statement' => $this->exportMemberStatement($request, $from, $to),
        };
    }

    private function exportTrialBalance(CarbonImmutable $from, CarbonImmutable $to)
    {
        $rows = $this->reports->trialBalance($this->group(), $from, $to);

        return $this->csv('trial-balance-'.$to->format('Ymd').'.csv',
            ['Code', 'Account', 'Type', 'Debit', 'Credit', 'Balance'],
            collect($rows)->map(fn (array $row) => [
                $row['account']->code,
                $row['account']->name,
                $row['account']->type,
                number_format($row['debit'] / 100, 2, '.', ''),
                number_format($row['credit'] / 100, 2, '.', ''),
                number_format($row['balance'] / 100, 2, '.', ''),
            ])
        );
    }

    private function exportIncomeStatement(CarbonImmutable $from, CarbonImmutable $to)
    {
        $report = $this->reports->incomeStatement($this->group(), $from, $to);
        $rows = collect();

        foreach ($report['income'] as $row) {
            $rows->push([$row['account']->code, $row['account']->name, 'Income', number_format($row['amount'] / 100, 2, '.', '')]);
        }

        foreach ($report['expense'] as $row) {
            $rows->push([$row['account']->code, $row['account']->name, 'Expense', number_format($row['amount'] / 100, 2, '.', '')]);
        }

        $rows->push(['', 'Net', '', number_format($report['net'] / 100, 2, '.', '')]);

        return $this->csv('income-statement-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv',
            ['Code', 'Account', 'Type', 'Amount'], $rows);
    }

    private function exportSavings()
    {
        $report = $this->reports->savingsSummary($this->group());

        return $this->csv('savings-summary-'.now()->format('Ymd').'.csv',
            ['Member', 'Member No', 'Account', 'Product', 'Balance', 'Available', 'Pledged'],
            collect($report['accounts'])->map(fn ($account) => [
                $account->member?->full_name,
                $account->member?->member_no,
                $account->account_no,
                $account->product?->name,
                number_format($account->balance / 100, 2, '.', ''),
                number_format($account->available_balance / 100, 2, '.', ''),
                number_format($account->pledged_amount / 100, 2, '.', ''),
            ])
        );
    }

    private function exportLoans()
    {
        $report = $this->reports->loanPortfolio($this->group());

        return $this->csv('loan-portfolio-'.now()->format('Ymd').'.csv',
            ['Loan No', 'Member', 'Product', 'Status', 'Principal', 'Outstanding', 'Arrears'],
            collect($report['loans'])->map(fn ($loan) => [
                $loan->loan_no,
                $loan->member?->full_name,
                $loan->product?->name,
                $loan->status,
                number_format($loan->principal / 100, 2, '.', ''),
                number_format($loan->outstandingTotal() / 100, 2, '.', ''),
                number_format($loan->overdueAmount() / 100, 2, '.', ''),
            ])
        );
    }

    private function exportMgr()
    {
        $report = $this->reports->merryGoRoundSummary($this->group());

        return $this->csv('merry-go-round-summary-'.now()->format('Ymd').'.csv',
            ['Cycle', 'Frequency', 'Contribution', 'Start', 'End', 'Status', 'Expected pool'],
            collect($report['cycles'])->map(fn ($cycle) => [
                $cycle->name,
                $cycle->frequency,
                number_format($cycle->contribution_amount / 100, 2, '.', ''),
                $cycle->start_date?->toDateString(),
                $cycle->end_date?->toDateString(),
                $cycle->status,
                number_format($cycle->expectedPool() / 100, 2, '.', ''),
            ])
        );
    }

    private function exportMemberStatement(Request $request, CarbonImmutable $from, CarbonImmutable $to)
    {
        $member = $request->filled('member_id')
            ? Member::query()->findOrFail($request->integer('member_id'))
            : null;

        abort_if($member === null, 422, 'Select a member to export.');

        return $this->csv('member-statement-'.$member->member_no.'-'.$to->format('Ymd').'.csv',
            ['Date', 'Type', 'Description', 'Debit', 'Credit', 'Reference'],
            collect($this->reports->memberStatement($member, $from, $to))->map(fn (array $row) => [
                $row['date'],
                $row['type'],
                $row['description'],
                number_format($row['debit'] / 100, 2, '.', ''),
                number_format($row['credit'] / 100, 2, '.', ''),
                $row['reference'],
            ])
        );
    }

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable}
     */
    private function period(Request $request): array
    {
        $from = $request->filled('from') ? CarbonImmutable::parse($request->date('from')) : CarbonImmutable::now()->startOfYear();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->date('to')) : CarbonImmutable::now();

        return [$from, $to];
    }
}
