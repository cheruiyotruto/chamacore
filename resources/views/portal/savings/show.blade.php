@extends('layouts.portal')

@section('title', $account->product?->name ?? 'Savings account')

@section('content')
    @php
        $creditTotal = (int) $transactions->getCollection()->where('direction', 'credit')->sum('amount');
        $debitTotal = (int) $transactions->getCollection()->where('direction', 'debit')->sum('amount');
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $account->product?->name ?? 'Savings account' }}</h1>
                <x-badge :status="$account->status" />
            </div>
            <p class="mt-1 font-mono text-xs text-slate-500">{{ $account->account_no }} · opened {{ $account->opened_at?->format('d M Y') ?? '—' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('portal.savings.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="arrow-left" class="h-4 w-4" /> All savings
            </a>
            <a href="{{ route('portal.savings.statement', $account) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Statement
            </a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Balance" :value="\App\Support\Money::format($account->balance)" hint="Current balance" />
        <x-stat label="Available" :value="\App\Support\Money::format($account->available_balance)" hint="Withdrawable now" tone="info" />
        <x-stat label="Pledged" :value="\App\Support\Money::format($account->pledged_amount)" hint="Held as security" tone="warning" />
        <x-stat label="Loan eligible" :value="\App\Support\Money::format($account->eligibleBalance())" :hint="$account->product?->qualifies_for_loan ? 'Qualifies for loans' : 'Not loan-eligible'" :tone="$account->product?->qualifies_for_loan ? 'brand' : 'neutral'" />
    </div>

    <x-card :padding="false" class="mt-6">
        <form method="GET" action="{{ route('portal.savings.show', $account) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-40">
                <x-input name="from" type="date" label="From" :value="request('from')" />
            </div>
            <div class="w-full sm:w-40">
                <x-input name="to" type="date" label="To" :value="request('to')" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Apply
            </button>
            <a href="{{ route('portal.savings.show', $account) }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
        </form>

        @if ($transactions->isEmpty())
            <x-empty title="No transactions" message="No savings movements for the selected period." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3 text-right">Credit</th>
                            <th class="px-4 py-3 text-right">Debit</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($transactions as $transaction)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $transaction->transaction_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-700">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</td>
                                <td class="max-w-xs px-4 py-2.5 text-slate-600">{{ $transaction->description ?: '—' }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-slate-400">{{ $transaction->reference ?: '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-emerald-700">{{ $transaction->direction === 'credit' ? \App\Support\Money::format($transaction->amount) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-rose-700">{{ $transaction->direction === 'debit' ? \App\Support\Money::format($transaction->amount) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($transaction->balance_after)</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Page totals</td>
                            <td class="px-4 py-3 text-right font-semibold text-emerald-700">@money($creditTotal)</td>
                            <td class="px-4 py-3 text-right font-semibold text-rose-700">@money($debitTotal)</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($account->balance)</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $transactions->links() }}</div>
        @endif
    </x-card>

    <div class="mt-5 grid gap-5 sm:grid-cols-2">
        <x-card title="Account terms">
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-500">Product type</dt>
                    <dd class="font-medium text-slate-800">{{ ucfirst($account->product?->type ?? 'savings') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-500">Interest</dt>
                    <dd class="font-medium text-slate-800">{{ $account->product?->interest_rate_bps ? \App\Support\Money::percentFromBps($account->product->interest_rate_bps) : 'None' }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-500">Minimum balance</dt>
                    <dd class="font-medium text-slate-800">@money($account->product?->min_balance ?? 0)</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt class="text-slate-500">Withdrawals</dt>
                    <dd class="font-medium text-slate-800">{{ $account->product?->allows_withdrawal ? 'Allowed' : 'Restricted' }}</dd>
                </div>
            </dl>
        </x-card>

        <x-card title="Need a withdrawal?">
            <p class="text-sm text-slate-600">Withdrawals are processed by the treasurer or through a signed request at the office. Pledged balances remain locked until the related loan is settled.</p>
            <a href="{{ route('portal.support.index') }}" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="chat" class="h-4 w-4" /> Contact support
            </a>
        </x-card>
    </div>
@endsection
