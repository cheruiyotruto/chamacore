@extends('layouts.portal')

@section('title', 'My overview')

@section('content')
    @php
        $initials = $member?->initials() ?? 'ME';
        $hasGuarantorRequests = $guaranteeRequests->isNotEmpty();
        $hasFines = $fines->isNotEmpty();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ $initials }}</span>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-slate-900">Welcome, {{ $member->first_name }}</h1>
                <p class="text-sm text-slate-500">{{ $currentGroup?->name }} · Member #{{ $member->member_no }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('portal.loans.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Apply for loan
            </a>
            <a href="{{ route('portal.wallet.show') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="wallet" class="h-4 w-4" /> Top up wallet
            </a>
        </div>
    </div>

    @if ($hasGuarantorRequests)
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="font-medium">You have {{ $guaranteeRequests->count() }} pending guarantee request(s).</p>
                <a href="{{ route('portal.loans.index') }}" class="font-semibold text-amber-900 underline-offset-2 hover:underline">Review now</a>
            </div>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Savings balance" :value="\App\Support\Money::format($savingsTotal)" :hint="$savingsAccounts->count().' account(s)'" />
        <x-stat label="Loan balance" :value="\App\Support\Money::format($loanBalance)" :hint="$loans->count().' loan(s)'" tone="warning" />
        <x-stat label="Wallet" :value="\App\Support\Money::format($wallet?->balance ?? 0)" hint="Available balance" tone="info" />
        <x-stat label="Investments" :value="\App\Support\Money::format($investmentSummary['current_value'])" :hint="$investmentSummary['active'].' active · '.$investmentSummary['matured'].' matured'" tone="brand" />
        <x-stat label="Active merry-go-rounds" :value="number_format($participations->count())" hint="Your participations" tone="brand" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="My savings" :subtitle="$savingsAccounts->count().' account(s)'" :padding="false">
                <x-slot:actions>
                    <a href="{{ route('portal.savings.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        View all <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </x-slot:actions>

                @if ($savingsAccounts->isEmpty())
                    <x-empty title="No savings accounts" message="Contact the office to open a savings account." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($savingsAccounts as $account)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('portal.savings.show', $account) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $account->product?->name ?? 'Savings' }}</a>
                                    <p class="font-mono text-xs text-slate-400">{{ $account->account_no }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900">@money($account->balance)</p>
                                    <p class="text-xs text-slate-500">Available: @money($account->available_balance)</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Loans" :subtitle="$loans->count().' loan(s)'" :padding="false">
                <x-slot:actions>
                    <a href="{{ route('portal.loans.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        View all <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </x-slot:actions>

                @if ($loans->isEmpty())
                    <x-empty title="No loans" message="You have not applied for a loan yet." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($loans as $loan)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('portal.loans.show', $loan) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $loan->loan_no }}</a>
                                    <p class="text-xs text-slate-500">{{ $loan->product?->name ?? 'Loan' }} · {{ $loan->statusLabel() }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900">@money($loan->isActive() ? $loan->outstandingTotal() : ($loan->approved_amount ?: $loan->requested_amount))</p>
                                    <p class="text-xs text-slate-500">{{ $loan->isActive() ? 'Outstanding' : 'Amount' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="My investments" :subtitle="$memberInvestments->count().' investment(s)'" :padding="false">
                <x-slot:actions>
                    <a href="{{ route('portal.investments.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">
                        <x-icon name="plus" class="h-3.5 w-3.5" /> Invest
                    </a>
                    <a href="{{ route('portal.investments.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        View all <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </x-slot:actions>

                @if ($memberInvestments->isEmpty())
                    <x-empty title="No investments" message="Choose an investment product and start earning interest." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($memberInvestments as $investment)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('portal.investments.show', $investment) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $investment->product?->name ?? 'Investment' }}</a>
                                    <p class="text-xs text-slate-500">{{ $investment->reference_no }} · {{ $investment->statusLabel() }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900">@money($investment->totalValue())</p>
                                    <p class="text-xs text-slate-500">Matures {{ $investment->maturity_date?->format('d M Y') ?? '—' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Recent activity" :subtitle="count($recentTransactions).' item(s)'" :padding="false">
                @if (count($recentTransactions) === 0)
                    <x-empty title="No recent activity" message="Your savings and wallet transactions will appear here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($recentTransactions as $tx)
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ $tx['label'] }}</p>
                                    <p class="text-xs text-slate-400">{{ $tx['date'] ? \Illuminate\Support\Carbon::parse($tx['date'])->format('d M Y') : '—' }}</p>
                                </div>
                                <p class="text-sm font-semibold {{ $tx['direction'] === 'credit' ? 'text-emerald-700' : 'text-rose-700' }}">
                                    {{ $tx['direction'] === 'credit' ? '+' : '-' }}@money($tx['amount'])
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Upcoming repayments" :subtitle="$upcoming->count().' installment(s)'" :padding="false">
                @if ($upcoming->isEmpty())
                    <x-empty title="Nothing due" message="You have no upcoming loan repayments." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($upcoming as $schedule)
                            <div class="px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-slate-800">{{ $schedule->loan?->loan_no }}</p>
                                        <p class="text-xs text-slate-500">Due {{ $schedule->due_date?->format('d M Y') ?? '—' }} · Inst. {{ $schedule->installment_no }}</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-slate-900">@money($schedule->outstanding())</p>
                                        <x-badge :status="$schedule->status" />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            @if ($hasFines)
                <x-card title="Fines" :subtitle="$fines->count().' outstanding'" :padding="false">
                    <div class="divide-y divide-slate-100">
                        @foreach ($fines as $fine)
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <p class="text-sm font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $fine->type)) }}</p>
                                    <p class="text-xs text-slate-500">Due {{ $fine->due_date?->format('d M Y') ?? '—' }}</p>
                                </div>
                                <p class="text-sm font-semibold text-rose-700">@money($fine->outstanding())</p>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <x-card title="Merry-go-round" :subtitle="$participations->count().' cycle(s)'" :padding="false">
                <x-slot:actions>
                    <a href="{{ route('portal.mgr.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        View all <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </x-slot:actions>

                @if ($participations->isEmpty())
                    <x-empty title="Not enrolled" message="You are not part of any merry-go-round cycle yet." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($participations as $participation)
                            <a href="{{ route('portal.mgr.show', $participation->cycle) }}" class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                                <div>
                                    <p class="text-sm font-medium text-slate-800">{{ $participation->cycle?->name }}</p>
                                    <p class="text-xs text-slate-500">{{ ucfirst($participation->cycle?->frequency ?? '—') }} · position {{ $participation->position }} in rotation</p>
                                </div>
                                <x-icon name="arrow-right" class="h-4 w-4 text-slate-400" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Quick links">
                <div class="grid gap-2">
                    @if ($documentsEnabled)
                        <a href="{{ route('portal.documents.index') }}" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                            <span class="flex items-center gap-2"><x-icon name="folder" class="h-4 w-4 text-slate-400" /> My documents</span>
                            <x-icon name="arrow-right" class="h-4 w-4 text-slate-400" />
                        </a>
                    @endif
                    <a href="{{ route('portal.governance.index') }}" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                        <span class="flex items-center gap-2"><x-icon name="scale" class="h-4 w-4 text-slate-400" /> Policies &amp; voting</span>
                        <x-icon name="arrow-right" class="h-4 w-4 text-slate-400" />
                    </a>
                    <a href="{{ route('portal.support.index') }}" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">
                        <span class="flex items-center gap-2"><x-icon name="chat" class="h-4 w-4 text-slate-400" /> Support tickets</span>
                        <x-icon name="arrow-right" class="h-4 w-4 text-slate-400" />
                    </a>
                </div>
            </x-card>
        </div>
    </div>
@endsection
