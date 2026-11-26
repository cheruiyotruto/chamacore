@extends('layouts.app')

@section('title', 'Savings '.$account->account_no)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $isCredit = fn ($transaction) => $transaction->direction === 'credit';
    @endphp

    <x-page-header :title="'Account '.$account->account_no" :subtitle="($account->member?->full_name ?? 'Unknown member').' · '.($account->product?->name ?? 'No product').' · opened '.($account->opened_at?->format('d M Y') ?? '—')">
        <a href="{{ route('savings.statement', $account) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="download" class="h-4 w-4" /> Statement (CSV)
        </a>
        @if ($account->member)
            <a href="{{ route('members.show', $account->member) }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
                <x-icon name="users" class="h-4 w-4" /> Member profile
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Balance" :value="\App\Support\Money::format($account->balance)" hint="Total saved in this account" />
        <x-stat label="Available" :value="\App\Support\Money::format($account->available_balance)" hint="After pledges and holds" tone="info" />
        <x-stat label="Pledged" :value="\App\Support\Money::format($account->pledged_amount)" hint="Committed as loan security" tone="warning" />
        <x-stat label="Loan-eligible" :value="\App\Support\Money::format($account->eligibleBalance())" :hint="($account->product?->qualifies_for_loan ?? false) ? 'Qualifies for loan limits' : 'Product does not qualify'" tone="neutral" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            @can('deposit', $account)
                <x-card title="Record a deposit" subtitle="Deposits are posted to the ledger immediately.">
                    <form method="POST" action="{{ route('savings.deposit', $account) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf
                        <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                        <x-select name="method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'wallet' => 'Member wallet']" required />
                        <x-input name="reference" label="Reference" placeholder="Auto-generated" />
                        <x-input name="description" label="Description" placeholder="Optional" />
                        <div class="sm:col-span-2 lg:col-span-4">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                <x-icon name="plus" class="h-4 w-4" /> Post deposit
                            </button>
                        </div>
                    </form>
                </x-card>
            @endcan

            @can('withdraw', $account)
                <x-card title="Record a withdrawal" subtitle="Withdrawals check available balance and product rules.">
                    <form method="POST" action="{{ route('savings.withdraw', $account) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf
                        <x-input name="amount" type="number" step="0.01" min="0.01" :max="number_format($account->available_balance / 100, 2, '.', '')" label="Amount" required />
                        <x-select name="method" label="Method" :options="['cash' => 'Cash', 'bank' => 'Bank', 'wallet' => 'Member wallet']" required />
                        <x-input name="reference" label="Reference" placeholder="Auto-generated" />
                        <x-input name="description" label="Description" placeholder="Optional" />
                        <div class="sm:col-span-2 lg:col-span-4">
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                <x-icon name="arrow-right" class="h-4 w-4" /> Post withdrawal
                            </button>
                        </div>
                    </form>
                </x-card>
            @endcan

            <x-card title="Transactions" :subtitle="$transactions->total().' entr'.($transactions->total() === 1 ? 'y' : 'ies')" :padding="false">
                <form method="GET" action="{{ route('savings.show', $account) }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-3">
                    <x-input name="from" type="date" label="From" :value="request('from')" />
                    <x-input name="to" type="date" label="To" :value="request('to')" />
                    <div class="flex items-end gap-2">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                            <x-icon name="search" class="h-4 w-4" /> Filter
                        </button>
                        <a href="{{ route('savings.show', $account) }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
                    </div>
                </form>

                @if ($transactions->isEmpty())
                    <x-empty title="No transactions" message="Deposits and withdrawals will appear here." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Date</th>
                                    <th class="px-4 py-2.5">Type</th>
                                    <th class="px-4 py-2.5">Reference</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                    <th class="px-4 py-2.5 text-right">Balance after</th>
                                    <th class="px-4 py-2.5">Journal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($transactions as $transaction)
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $transaction->transaction_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-4 py-2.5">
                                            <p class="font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</p>
                                            @if ($transaction->description)
                                                <p class="text-xs text-slate-400">{{ $transaction->description }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $transaction->reference ?: '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold {{ $isCredit($transaction) ? 'text-emerald-600' : 'text-rose-600' }}">
                                            {{ $isCredit($transaction) ? '+' : '−' }}@money($transaction->amount)
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-700">@money($transaction->balance_after)</td>
                                        <td class="px-4 py-2.5">
                                            @if ($transaction->journal_entry_id && $can('accounting.view'))
                                                <a href="{{ route('accounting.journals.show', $transaction->journal_entry_id) }}" class="text-xs font-medium text-brand-700 hover:text-brand-800">
                                                    {{ $transaction->journalEntry?->entry_no ?? 'View' }}
                                                </a>
                                            @else
                                                <span class="text-xs text-slate-400">{{ $transaction->journalEntry?->entry_no ?? '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-slate-200 px-4 py-3">{{ $transactions->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Account details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$account->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Product</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $account->product?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Product type</dt>
                        <dd><x-badge :status="$account->product?->type ?? 'neutral'" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Interest</dt>
                        <dd class="font-medium text-slate-800">@bps($account->product?->interest_rate_bps ?? 0) p.a.</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Minimum balance</dt>
                        <dd class="font-medium text-slate-800">@money($account->product?->min_balance ?? 0)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Withdrawals</dt>
                        <dd class="font-medium text-slate-800">{{ ($account->product?->allows_withdrawal ?? false) ? 'Allowed' : 'Locked' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Opened</dt>
                        <dd class="font-medium text-slate-800">{{ $account->opened_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Member">
                @if ($account->member)
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                            {{ $account->member->initials() }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $account->member->full_name }}</p>
                            <p class="text-xs text-slate-500">{{ $account->member->member_no }} · {{ $account->member->phone ?: 'no phone' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
                        <div>
                            <p class="text-xs text-slate-500">Status</p>
                            <p class="mt-1"><x-badge :status="$account->member->status" /></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">KYC</p>
                            <p class="mt-1"><x-badge :status="$account->member->kyc_status" /></p>
                        </div>
                    </div>
                    <a href="{{ route('members.show', $account->member) }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="users" class="h-4 w-4" /> Open member profile
                    </a>
                @else
                    <p class="text-sm text-slate-500">This account is not linked to a member record.</p>
                @endif
            </x-card>

            <x-card title="Product rules">
                @if ($account->product)
                    <ul class="space-y-2 text-sm text-slate-600">
                        <li class="flex items-center gap-2">
                            <x-icon name="check" class="h-4 w-4 {{ $account->product->qualifies_for_loan ? 'text-emerald-500' : 'text-slate-300' }}" />
                            Qualifies as loan security
                        </li>
                        <li class="flex items-center gap-2">
                            <x-icon name="check" class="h-4 w-4 {{ $account->product->is_mandatory ? 'text-emerald-500' : 'text-slate-300' }}" />
                            Mandatory contribution product
                        </li>
                        <li class="flex items-center gap-2">
                            <x-icon name="check" class="h-4 w-4 {{ $account->product->allows_withdrawal ? 'text-emerald-500' : 'text-slate-300' }}" />
                            Member withdrawals permitted
                        </li>
                        <li class="flex items-center gap-2">
                            <x-icon name="check" class="h-4 w-4 {{ $account->product->is_active ? 'text-emerald-500' : 'text-slate-300' }}" />
                            Product active
                        </li>
                    </ul>
                    @if ($can('accounting.view'))
                        <a href="{{ route('accounting.ledger', ['account' => \App\Services\LedgerService::SAVINGS_LIABILITY]) }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <x-icon name="book" class="h-4 w-4" /> Savings control ledger
                        </a>
                    @endif
                @else
                    <p class="text-sm text-slate-500">The product for this account has been removed.</p>
                @endif
            </x-card>
        </div>
    </div>
@endsection
