@extends('layouts.portal')

@section('title', 'My investments')

@section('content')
    @php
        $openInvestments = $investments->filter(fn ($investment) => $investment->isOpen());
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">My investments</h1>
            <p class="mt-1 text-sm text-slate-500">Grow your wallet balance by investing in group products and earning interest.</p>
        </div>
        <a href="{{ route('portal.investments.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="plus" class="h-4 w-4" /> Place investment
        </a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Principal invested" :value="\App\Support\Money::format($summary['principal'])" hint="Across open investments" />
        <x-stat label="Interest earned" :value="\App\Support\Money::format($summary['accrued_interest'])" hint="Accrued to date" tone="brand" />
        <x-stat label="Current value" :value="\App\Support\Money::format($summary['current_value'])" hint="Principal plus accrued interest" tone="info" />
        <x-stat label="Open investments" :value="number_format($summary['active'] + $summary['matured'])" :hint="$summary['matured'].' matured · '.$summary['invested'].' lifetime'" tone="neutral" />
    </div>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="My investments" :subtitle="$openInvestments->count().' open investment(s)'" :padding="false">
                @if ($investments->isEmpty())
                    <x-empty title="No investments yet" message="Pick a product below and place your first investment from your wallet." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($investments as $investment)
                            @php
                                $progress = $investment->progressPercent();
                                $badgeTone = $investment->status === 'active' ? 'active' : ($investment->status === 'matured' ? 'info' : 'inactive');
                            @endphp
                            <div class="px-4 py-3.5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('portal.investments.show', $investment) }}" class="font-medium text-slate-900 hover:text-brand-700">
                                                {{ $investment->product?->name ?? 'Investment' }}
                                            </a>
                                            <x-badge :status="$badgeTone" :label="$investment->statusLabel()" />
                                        </div>
                                        <p class="mt-0.5 font-mono text-xs text-slate-400">{{ $investment->reference_no }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            {{ $investment->start_date?->format('d M Y') }} → {{ $investment->maturity_date?->format('d M Y') }}
                                            · {{ $investment->duration_months }} month(s)
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold text-slate-900">@money($investment->totalValue())</p>
                                        <p class="text-xs text-slate-500">Principal @money($investment->principal)</p>
                                        <p class="text-xs text-emerald-700">+ @money($investment->accrued_interest) interest</p>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <div class="flex items-center justify-between text-[11px] font-medium text-slate-500">
                                        <span>Term progress</span>
                                        <span>{{ $progress }}%</span>
                                    </div>
                                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-full rounded-full bg-brand-500" style="width: {{ $progress }}%"></div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Available investment products" subtitle="Fund any of these directly from your wallet.">
                @if ($products->isEmpty())
                    <x-empty title="No investment products" message="The group has not published an investment product yet." />
                @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($products as $product)
                            <div class="flex flex-col justify-between rounded-xl border border-slate-200 p-4">
                                <div>
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="font-semibold text-slate-900">{{ $product->name }}</p>
                                            <p class="font-mono text-xs text-slate-400">{{ $product->code }}</p>
                                        </div>
                                        <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">@bps($product->interest_rate_bps) p.a.</span>
                                    </div>
                                    @if ($product->description)
                                        <p class="mt-2 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($product->description, 120) }}</p>
                                    @endif
                                    <dl class="mt-3 space-y-1 text-xs text-slate-500">
                                        <div class="flex justify-between gap-2"><dt>Interest period</dt><dd class="font-medium text-slate-700">{{ $product->periodLabel() }}</dd></div>
                                        <div class="flex justify-between gap-2"><dt>Method</dt><dd class="font-medium text-slate-700">{{ $product->methodLabel() }}</dd></div>
                                        <div class="flex justify-between gap-2"><dt>Term</dt><dd class="font-medium text-slate-700">{{ $product->duration_months }} month(s)</dd></div>
                                        <div class="flex justify-between gap-2"><dt>Minimum</dt><dd class="font-medium text-slate-700">@money($product->min_amount)</dd></div>
                                        <div class="flex justify-between gap-2"><dt>Early withdrawal</dt><dd class="font-medium text-slate-700">{{ $product->early_withdrawal_allowed ? 'Allowed' : 'Locked to maturity' }}</dd></div>
                                    </dl>
                                </div>
                                <a href="{{ route('portal.investments.create', ['product' => $product->id]) }}" class="mt-4 inline-flex items-center justify-center gap-2 rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                                    <x-icon name="trending-up" class="h-4 w-4" /> Invest
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Recent investment activity" :subtitle="$recent->count().' movement(s)'" :padding="false">
                @if ($recent->isEmpty())
                    <x-empty title="No activity" message="Deposits, interest and payouts will appear here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($recent as $transaction)
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</p>
                                    <p class="text-xs text-slate-400">{{ $transaction->transaction_date?->format('d M Y') ?? '—' }}</p>
                                </div>
                                <p class="text-sm font-semibold {{ $transaction->direction === 'credit' ? 'text-emerald-700' : 'text-rose-700' }}">
                                    {{ $transaction->direction === 'credit' ? '+' : '-' }}@money($transaction->amount)
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="How interest works">
                <ul class="space-y-2 text-xs text-slate-500">
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" /> Interest is quoted as an annual rate and pro-rated over each interest period.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" /> Simple interest is paid on your principal. Compound interest is added to the balance each period.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" /> Early withdrawal is only possible when the product allows it, and may attract a penalty.</li>
                </ul>
            </x-card>
        </div>
    </div>
@endsection
