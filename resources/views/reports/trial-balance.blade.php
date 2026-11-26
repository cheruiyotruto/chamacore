@extends('layouts.app')

@section('title', 'Trial balance')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canExport = $ctx->hasPermission('reports.export');
        $rows = collect($rows);
        $totalDebit = (int) $rows->sum('debit');
        $totalCredit = (int) $rows->sum('credit');
        $totalBalance = (int) $rows->sum('balance');
        $balanced = $totalDebit === $totalCredit;
    @endphp

    <x-page-header title="Trial balance" :subtitle="'Posted movements '.$from.' to '.$to">
        <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All reports
        </a>
        @if ($canExport)
            <a href="{{ route('reports.export', ['type' => 'trial_balance', 'from' => $from, 'to' => $to]) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Export CSV
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total debits" :value="\App\Support\Money::format($totalDebit)" hint="For the selected period" />
        <x-stat label="Total credits" :value="\App\Support\Money::format($totalCredit)" hint="For the selected period" tone="info" />
        <x-stat label="Difference" :value="\App\Support\Money::format($totalDebit - $totalCredit)" :hint="$balanced ? 'Debits equal credits' : 'Investigate the ledger'" :tone="$balanced ? 'brand' : 'danger'" />
        <x-stat label="Accounts" :value="number_format($rows->count())" hint="Included in this report" tone="neutral" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('reports.trial-balance') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-44">
                <x-input name="from" type="date" label="From" :value="$from" />
            </div>
            <div class="w-full sm:w-44">
                <x-input name="to" type="date" label="To" :value="$to" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Apply
            </button>
            <a href="{{ route('reports.trial-balance') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
        </form>

        @if ($rows->isEmpty())
            <x-empty title="No ledger activity" message="Nothing has been posted for the selected period." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Account</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Normal</th>
                            <th class="px-4 py-3 text-right">Debit</th>
                            <th class="px-4 py-3 text-right">Credit</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-500">{{ $row['account']->code }}</td>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('accounting.ledger', ['account' => $row['account']->code, 'from' => $from, 'to' => $to]) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $row['account']->name }}</a>
                                </td>
                                <td class="px-4 py-2.5"><x-badge :status="$row['account']->type" :label="ucfirst($row['account']->type)" /></td>
                                <td class="px-4 py-2.5 text-slate-600">{{ ucfirst($row['account']->normal_balance) }}</td>
                                <td class="px-4 py-2.5 text-right text-slate-700">@money($row['debit'])</td>
                                <td class="px-4 py-2.5 text-right text-slate-700">@money($row['credit'])</td>
                                <td class="px-4 py-2.5 text-right font-semibold {{ $row['balance'] < 0 ? 'text-rose-700' : 'text-slate-900' }}">@money($row['balance'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Totals</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($totalDebit)</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($totalCredit)</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($totalBalance)</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-card>
@endsection
