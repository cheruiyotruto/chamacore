@extends('layouts.app')

@section('title', $investment->reference_no)

@section('content')
    @php
        $progress = $investment->progressPercent();
        $terms = $investment->terms_snapshot ?? [];
        $early = $investment->maturity_date?->isFuture() ?? false;
        $badgeTone = $investment->status === 'matured' ? 'info' : ($investment->status === 'active' ? 'active' : 'inactive');
    @endphp

    <x-page-header :title="$investment->product?->name ?? 'Investment'" :subtitle="$investment->reference_no.' · '.($investment->member?->full_name ?? 'Member')">
        @if ($canManage)
            <form method="POST" action="{{ route('investments.accrue', $investment) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    <x-icon name="refresh" class="h-4 w-4" /> Accrue due interest
                </button>
            </form>
        @endif
        <a href="{{ route('investments.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All investments
        </a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Principal" :value="\App\Support\Money::format($investment->principal)" hint="Amount invested" />
        <x-stat label="Accrued interest" :value="\App\Support\Money::format($investment->accrued_interest)" :hint="'of '. \App\Support\Money::format($investment->expected_interest).' expected'" tone="warning" />
        <x-stat label="Current value" :value="\App\Support\Money::format($investment->totalValue())" hint="Payout if settled now" tone="info" />
        <x-stat label="Maturity" :value="$investment->maturity_date?->format('d M Y') ?? '—'" :hint="$investment->duration_months.' month term'" tone="neutral" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Interest schedule" :subtitle="$investment->schedules->count().' period(s)'" :padding="false">
                @if ($investment->schedules->isEmpty())
                    <x-empty title="No schedule" message="This investment has no interest schedule." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">#</th>
                                    <th class="px-4 py-2.5">Period</th>
                                    <th class="px-4 py-2.5">Due</th>
                                    <th class="px-4 py-2.5 text-right">Opening</th>
                                    <th class="px-4 py-2.5 text-right">Interest</th>
                                    <th class="px-4 py-2.5 text-right">Closing</th>
                                    <th class="px-4 py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($investment->schedules as $period)
                                    <tr>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $period->period_no }}</td>
                                        <td class="px-4 py-2.5 text-slate-600">
                                            {{ $period->period_start?->format('d M Y') }} → {{ $period->period_end?->format('d M Y') }}
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $period->due_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($period->opening_balance)</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-emerald-700">@money($period->interest_amount)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-700">@money($period->closing_balance)</td>
                                        <td class="px-4 py-2.5">
                                            <x-badge :status="$period->status === 'accrued' ? 'credited' : 'scheduled'" :label="$period->status === 'accrued' ? 'Accrued' : 'Scheduled'" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Transactions" :subtitle="$investment->transactions->count().' movement(s)'" :padding="false">
                @if ($investment->transactions->isEmpty())
                    <x-empty title="No transactions" message="Investment movements will appear here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($investment->transactions as $transaction)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">
                                        {{ $transaction->description ?: ucfirst(str_replace('_', ' ', $transaction->type)) }}
                                    </p>
                                    <p class="text-xs text-slate-400">
                                        {{ $transaction->transaction_date?->format('d M Y') ?? '—' }}
                                        @if ($transaction->reference)
                                            · <span class="font-mono">{{ $transaction->reference }}</span>
                                        @endif
                                        @if ($transaction->creator)
                                            · by {{ $transaction->creator->name }}
                                        @endif
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold {{ $transaction->direction === 'credit' ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $transaction->direction === 'credit' ? '+' : '-' }}@money($transaction->amount)
                                    </p>
                                    <p class="text-xs text-slate-400">Balance @money($transaction->balance_after)</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Member">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ $investment->member?->initials() ?? '—' }}</span>
                    <div class="min-w-0">
                        <a href="{{ route('members.show', $investment->member_id) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $investment->member?->full_name ?? 'Member' }}</a>
                        <p class="text-xs text-slate-500">{{ $investment->member?->member_no }}</p>
                    </div>
                </div>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$badgeTone" :label="$investment->statusLabel()" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Start date</dt>
                        <dd class="font-medium text-slate-800">{{ $investment->start_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Rate</dt>
                        <dd class="font-medium text-slate-800">@bps($investment->interest_rate_bps) p.a.</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Interest period</dt>
                        <dd class="font-medium text-slate-800">{{ $investment->product?->periodLabel() ?? ucfirst(str_replace('_', ' ', $investment->interest_period)) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Method</dt>
                        <dd class="font-medium text-slate-800">{{ $investment->product?->methodLabel() ?? ucfirst($investment->interest_method) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Paid interest</dt>
                        <dd class="font-medium text-slate-800">@money($investment->paid_interest)</dd>
                    </div>
                </dl>
                <div class="mt-4">
                    <div class="flex items-center justify-between text-xs font-medium text-slate-500">
                        <span>Term progress</span>
                        <span>{{ $progress }}%</span>
                    </div>
                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $progress }}%"></div>
                    </div>
                </div>
            </x-card>

            @if ($investment->isOpen())
                <x-card title="Settle investment">
                    @if ($canWithdraw)
                        <p class="text-sm text-slate-600">
                            {{ $early ? 'This will settle the investment before maturity.' : 'This investment has matured.' }}
                            Select where the payout goes.
                        </p>
                        <form method="POST" action="{{ route('investments.settle', $investment) }}" class="mt-3 space-y-3"
                              onsubmit="return confirm('Settle this investment now?');">
                            @csrf
                            <x-select name="destination" label="Payout destination" :options="['wallet' => 'Member wallet', 'cash' => 'Cash', 'bank' => 'Bank']" value="wallet" required />
                            @if ($early)
                                <label class="flex items-center gap-2 text-xs text-slate-600">
                                    <input type="checkbox" name="force" value="1" class="rounded border-slate-300 text-brand-600"> Force early settlement even if the product locks funds
                                </label>
                            @endif
                            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                                <x-icon name="banknotes" class="h-4 w-4" /> Settle and pay out
                            </button>
                        </form>
                    @else
                        <p class="text-sm text-slate-600">This investment cannot be settled at this time.</p>
                    @endif
                </x-card>
            @else
                <x-card title="Settled">
                    <p class="text-sm text-slate-600">
                        Closed on {{ $investment->closed_at?->format('d M Y H:i') ?? '—' }}.
                    </p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">Total interest paid: @money($investment->paid_interest)</p>
                </x-card>
            @endif

            @if (! empty($terms))
                <x-card title="Product snapshot">
                    <dl class="space-y-2 text-xs">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Product</dt><dd class="font-medium text-slate-800">{{ $terms['product_name'] ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Code</dt><dd class="font-mono text-slate-700">{{ $terms['product_code'] ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Rate</dt><dd class="font-medium text-slate-800">@bps($terms['interest_rate_bps'] ?? 0) p.a.</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Term</dt><dd class="font-medium text-slate-800">{{ $terms['duration_months'] ?? 0 }} month(s)</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Early exit</dt><dd class="font-medium text-slate-800">{{ ! empty($terms['early_withdrawal_allowed']) ? 'Allowed' : 'Locked' }}</dd></div>
                    </dl>
                </x-card>
            @endif
        </div>
    </div>
@endsection
