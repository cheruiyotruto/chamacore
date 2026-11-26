@extends('layouts.portal')

@section('title', 'My savings')

@section('content')
    @php
        $total = (int) $accounts->sum('balance');
        $available = (int) $accounts->sum('available_balance');
        $pledged = (int) $accounts->sum('pledged_amount');
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">My savings</h1>
        <p class="mt-1 text-sm text-slate-500">Balances, pledges and recent movements across your savings accounts.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Total savings" :value="\App\Support\Money::format($total)" :hint="$accounts->count().' account(s)'" />
        <x-stat label="Available" :value="\App\Support\Money::format($available)" hint="Withdrawable now" tone="info" />
        <x-stat label="Pledged" :value="\App\Support\Money::format($pledged)" hint="Held for loans and guarantees" tone="warning" />
        <x-stat label="Accounts" :value="number_format($accounts->count())" hint="Open savings accounts" tone="neutral" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Accounts" :subtitle="$accounts->count().' account(s)'" :padding="false">
                @if ($accounts->isEmpty())
                    <x-empty title="No savings accounts" message="Ask the office to open a savings account for you." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($accounts as $account)
                            <a href="{{ route('portal.savings.show', $account) }}" class="block px-4 py-4 hover:bg-slate-50">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900">{{ $account->product?->name ?? 'Savings account' }}</p>
                                        <p class="font-mono text-xs text-slate-400">{{ $account->account_no }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ ucfirst($account->product?->type ?? 'savings') }}
                                            @if ($account->product?->interest_rate_bps)
                                                · @bps($account->product->interest_rate_bps) interest
                                            @endif
                                            @if ($account->product?->contribution_amount)
                                                · @money($account->product->contribution_amount) {{ $account->product->contribution_frequency ?? 'contribution' }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-lg font-semibold text-slate-900">@money($account->balance)</p>
                                        <p class="text-xs text-slate-500">Available @money($account->available_balance)</p>
                                        <x-badge :status="$account->status" />
                                    </div>
                                </div>
                                @if ($account->pledged_amount > 0)
                                    <p class="mt-2 text-xs font-medium text-amber-700">@money($account->pledged_amount) pledged as security</p>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Recent transactions" :subtitle="$recent->count().' latest'" :padding="false">
                @if ($recent->isEmpty())
                    <x-empty title="No transactions" message="Savings deposits and withdrawals appear here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($recent as $transaction)
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $transaction->account?->product?->name ?? 'Savings' }} · {{ $transaction->transaction_date?->format('d M Y') ?? '—' }}
                                    </p>
                                    @if ($transaction->description)
                                        <p class="truncate text-xs text-slate-400">{{ $transaction->description }}</p>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold {{ $transaction->direction === 'credit' ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $transaction->direction === 'credit' ? '+' : '-' }}@money($transaction->amount)
                                    </p>
                                    <p class="text-xs text-slate-400">Bal @money($transaction->balance_after)</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="How savings work">
                <ul class="space-y-2 text-sm text-slate-600">
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Mandatory contributions build your savings and loan eligibility.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Pledged savings cannot be withdrawn while securing a loan.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Open an account detail to download a full statement.</li>
                </ul>
            </x-card>

            <x-card title="Need to save more?">
                <p class="text-sm text-slate-600">Visit the office or use your wallet to transfer funds into savings. Your treasurer records all cash deposits.</p>
                <a href="{{ route('portal.wallet.show') }}" class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="wallet" class="h-4 w-4" /> Open wallet
                </a>
            </x-card>
        </div>
    </div>
@endsection
