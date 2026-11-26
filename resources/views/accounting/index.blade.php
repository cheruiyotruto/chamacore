@extends('layouts.app')

@section('title', 'Accounting')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $totalDebits = collect($trialBalance)->sum('debit');
        $totalCredits = collect($trialBalance)->sum('credit');
        $totalBalance = collect($trialBalance)->sum('balance');
        $accountTypes = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];
        $typeTone = fn (string $type) => match ($type) {
            'asset' => 'info',
            'liability' => 'warning',
            'equity' => 'brand',
            'income' => 'success',
            'expense' => 'danger',
            default => 'neutral',
        };
    @endphp

    <x-page-header title="Accounting" :subtitle="'Ledger and bank accounts · '.$from.' to '.$to">
        @if ($can('accounting.journal'))
            <a href="{{ route('accounting.journals.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New journal
            </a>
        @endif
        @if ($can('accounting.manage'))
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="cog" class="h-4 w-4" /> Add account
                </summary>
                <form method="POST" action="{{ route('accounting.accounts.store') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Create a ledger account</p>
                    <x-input name="code" label="Code" placeholder="e.g. 4050" required />
                    <x-input name="name" label="Name" required />
                    <x-select name="type" label="Type" :options="$accountTypes" required />
                    <x-select name="normal_balance" label="Normal balance" :options="['debit' => 'Debit', 'credit' => 'Credit']" required />
                    <x-input name="description" label="Description" />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create account</button>
                </form>
            </details>
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="banknotes" class="h-4 w-4" /> Add bank
                </summary>
                <form method="POST" action="{{ route('accounting.bank-accounts.store') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Add a bank account</p>
                    <x-input name="name" label="Account name" required />
                    <x-input name="bank_name" label="Bank" required />
                    <x-input name="account_no" label="Account number" required />
                    <x-input name="branch" label="Branch" />
                    <x-input name="opening_balance" type="number" step="0.01" min="0" label="Opening balance" />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Add bank account</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total debits" :value="\App\Support\Money::format($totalDebits)" hint="For the selected period" />
        <x-stat label="Total credits" :value="\App\Support\Money::format($totalCredits)" hint="For the selected period" tone="info" />
        <x-stat label="Net balance" :value="\App\Support\Money::format($totalBalance)" hint="$totalDebits === $totalCredits ? 'Journals balance' : 'Review the ledger'" :tone="$totalDebits === $totalCredits ? 'brand' : 'warning'" />
        <x-stat label="Accounts" :value="number_format(count($trialBalance))" :hint="count($bankAccounts).' bank account(s)'" tone="neutral" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('accounting.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-44">
                <x-input name="from" type="date" label="From" :value="$from" />
            </div>
            <div class="w-full sm:w-44">
                <x-input name="to" type="date" label="To" :value="$to" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Apply
            </button>
            <a href="{{ route('accounting.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            <div class="ml-auto flex items-center gap-2">
                <a href="{{ route('accounting.journals') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="receipt" class="h-4 w-4" /> Journals
                </a>
                <a href="{{ route('accounting.ledger') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="book" class="h-4 w-4" /> General ledger
                </a>
            </div>
        </form>

        @if (empty($trialBalance))
            <x-empty title="No ledger accounts" message="The chart of accounts is created when the group is provisioned." class="m-4" />
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
                        @foreach ($trialBalance as $row)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-500">{{ $row['account']->code }}</td>
                                <td class="px-4 py-2.5">
                                    <a href="{{ route('accounting.ledger', ['account' => $row['account']->code, 'from' => $from, 'to' => $to]) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $row['account']->name }}</a>
                                    @if ($row['account']->is_system)
                                        <span class="ml-1 rounded bg-slate-100 px-1 py-0.5 text-[10px] font-medium uppercase text-slate-500">system</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5"><x-badge :status="$row['account']->type" :label="ucfirst($row['account']->type)" /></td>
                                <td class="px-4 py-2.5 text-slate-600">{{ ucfirst($row['account']->normal_balance) }}</td>
                                <td class="px-4 py-2.5 text-right text-slate-600">@money($row['debit'])</td>
                                <td class="px-4 py-2.5 text-right text-slate-600">@money($row['credit'])</td>
                                <td class="px-4 py-2.5 text-right font-semibold {{ $row['balance'] < 0 ? 'text-rose-700' : 'text-slate-900' }}">@money($row['balance'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="4" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Totals</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($totalDebits)</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($totalCredits)</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($totalBalance)</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-card>

    <x-card title="Bank accounts" :subtitle="count($bankAccounts).' account(s)'" :padding="false">
        @if (empty($bankAccounts))
            <x-empty title="No bank accounts" message="Add a bank account to reconcile bank statements." class="m-4" />
        @else
            <div class="divide-y divide-slate-100">
                @foreach ($bankAccounts as $bankAccount)
                    <div class="p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">{{ $bankAccount->name }}</p>
                                <p class="text-xs text-slate-500">{{ $bankAccount->bank_name }} · {{ $bankAccount->account_no }}{{ $bankAccount->branch ? ' · '.$bankAccount->branch : '' }}</p>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <p class="text-xs text-slate-500">Opening</p>
                                    <p class="text-sm font-medium text-slate-800">@money($bankAccount->opening_balance)</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-slate-500">Current</p>
                                    <p class="text-sm font-semibold text-slate-900">@money($bankAccount->current_balance)</p>
                                </div>
                                <x-badge :status="$bankAccount->is_active ? 'active' : 'inactive'" />
                            </div>
                        </div>

                        @if ($can('accounting.manage'))
                            <details class="mt-3">
                                <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-xs font-semibold text-brand-700 hover:text-brand-800">
                                    <x-icon name="scale" class="h-3.5 w-3.5" /> Reconcile statement
                                </summary>
                                <form method="POST" action="{{ route('accounting.bank-accounts.reconcile', $bankAccount) }}" class="mt-2 grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-4">
                                    @csrf
                                    <x-input name="statement_date" type="date" label="Statement date" :value="now()" required />
                                    <x-input name="statement_balance" type="number" step="0.01" label="Statement balance" required />
                                    <div class="sm:col-span-2">
                                        <x-input name="notes" label="Notes" placeholder="Optional" />
                                    </div>
                                    <div class="sm:col-span-4">
                                        <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save reconciliation</button>
                                    </div>
                                </form>
                            </details>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>
@endsection
