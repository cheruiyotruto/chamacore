@extends('layouts.portal')

@section('title', $cycle->name)

@section('content')
    @php
        $payout = $participant->payout;
        $payoutAmount = $payout?->amount ?? ($cycle->contribution_amount * max(1, $cycle->participants()->count()));
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('portal.mgr.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-700">
                <x-icon name="arrow-left" class="h-4 w-4" /> My merry-go-round
            </a>
            <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ $cycle->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ ucfirst($cycle->frequency) }} contributions of @money($cycle->contribution_amount)
                @if ($cycle->start_date)
                    · {{ $cycle->start_date->format('d M Y') }} – {{ $cycle->end_date?->format('d M Y') ?? 'ongoing' }}
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            <x-badge :status="$cycle->status" />
            <x-badge :status="$participant->status" :label="'Position '.$participant->position" />
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat label="Total paid" :value="\App\Support\Money::format($summary['paid'])" :hint="'Of '.\App\Support\Money::format($summary['paid'] + $summary['due']).' scheduled'" />
        <x-stat label="Outstanding" :value="\App\Support\Money::format($summary['due'])" hint="Pending and late contributions" tone="warning" />
        <x-stat label="Penalties" :value="\App\Support\Money::format($summary['penalties'])" hint="Charged on late contributions" tone="danger" />
        <x-stat label="My payout" :value="\App\Support\Money::format($payoutAmount)" :hint="$participant->payout_date?->format('d M Y') ?? $payout?->scheduled_date?->format('d M Y') ?? 'Date not scheduled'" tone="info" />
    </div>

    <x-card class="mt-6" title="Pay an outstanding contribution"
        subtitle="Wallet balance: {{ \App\Support\Money::format($walletBalance) }}. Amounts include any late penalty.">
        @if ($payable->isEmpty())
            <x-empty title="Nothing due" message="You are up to date for this cycle." />
        @else
            <div class="space-y-3">
                @foreach ($payable as $item)
                    @php
                        $contribution = $item['contribution'];
                    @endphp
                    <div class="rounded-lg border border-slate-200 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-900">
                                    Due {{ $contribution->due_date?->format('d M Y') }}
                                    @if ($contribution->status === 'late')
                                        <x-badge status="late" label="Late" />
                                    @endif
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    Contribution {{ \App\Support\Money::format($item['amount']) }}
                                    @if ($item['penalty'] > 0)
                                        + penalty {{ \App\Support\Money::format($item['penalty']) }}
                                    @endif
                                    @if (($item['savings'] ?? 0) > 0)
                                        + savings {{ \App\Support\Money::format($item['savings']) }}
                                    @endif
                                    · Total <span class="font-semibold text-slate-800">{{ \App\Support\Money::format($item['total']) }}</span>
                                </p>
                            </div>
                            <x-badge :status="$contribution->status" />
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <form method="POST" action="{{ route('portal.mgr.contributions.pay-wallet', $contribution) }}" class="space-y-3">
                                @csrf
                                <x-input name="manual_charge" type="number" step="0.01" min="0" label="Manual charge (if required)"
                                    help="Leave blank unless an administrator set a manual payment charge." />
                                <button class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                                    Pay {{ \App\Support\Money::format($item['total']) }} from wallet
                                </button>
                            </form>

                            <form method="POST" action="{{ route('portal.mgr.contributions.pay-mpesa', $contribution) }}" class="space-y-3">
                                @csrf
                                <x-input name="phone" label="M-Pesa phone number"
                                    :value="old('phone', auth()->user()?->phone ?? '')" placeholder="254712345678" required />
                                <x-input name="manual_charge" type="number" step="0.01" min="0" label="Manual charge (if required)" />
                                <button class="w-full rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    Request M-Pesa payment
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <div class="mt-6 grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Contributions" :subtitle="$contributions->total().' contribution(s)'" :padding="false">
                <div class="flex flex-wrap gap-1.5 border-b border-slate-100 px-4 py-3">
                    <a href="{{ route('portal.mgr.show', $cycle) }}"
                       class="rounded-lg px-2.5 py-1 text-xs font-semibold {{ ! request('status') ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">All</a>
                    @foreach ($statuses as $status)
                        <a href="{{ route('portal.mgr.show', ['cycle' => $cycle, 'status' => $status]) }}"
                           class="rounded-lg px-2.5 py-1 text-xs font-semibold {{ request('status') === $status ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ ucfirst($status) }}</a>
                    @endforeach
                </div>

                @if ($contributions->isEmpty())
                    <x-empty title="No contributions" message="Contributions for this cycle have not been generated." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Due date</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                    <th class="px-4 py-2.5 text-right">Penalty</th>
                                    <th class="px-4 py-2.5">Status</th>
                                    <th class="px-4 py-2.5">Paid</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($contributions as $contribution)
                                    <tr class="{{ $contribution->status === 'late' ? 'bg-amber-50/40' : 'hover:bg-slate-50' }}">
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $contribution->due_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($contribution->amount)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($contribution->penalty_amount)</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$contribution->status" /></td>
                                        <td class="px-4 py-2.5 text-slate-600">
                                            @if ($contribution->paid_at)
                                                {{ $contribution->paid_at->format('d M Y') }}
                                                @if ($contribution->reference)
                                                    <p class="font-mono text-xs text-slate-400">{{ $contribution->reference }}</p>
                                                @endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $contributions->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="My payout">
                @if ($payout)
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Amount</dt>
                            <dd class="font-semibold text-slate-900">@money($payout->amount)</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Scheduled</dt>
                            <dd class="font-medium text-slate-800">{{ $payout->scheduled_date?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Status</dt>
                            <dd><x-badge :status="$payout->status" /></dd>
                        </div>
                        @if ($payout->paid_at)
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-slate-500">Paid</dt>
                                <dd class="font-medium text-slate-800">{{ $payout->paid_at->format('d M Y') }}</dd>
                            </div>
                        @endif
                        @if ($payout->reference)
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-slate-500">Reference</dt>
                                <dd class="font-mono text-xs text-slate-700">{{ $payout->reference }}</dd>
                            </div>
                        @endif
                    </dl>
                @else
                    <p class="text-sm text-slate-600">Your payout is scheduled for <span class="font-semibold text-slate-900">{{ $participant->payout_date?->format('d M Y') ?? 'a date to be confirmed' }}</span>.</p>
                    <p class="mt-2 text-sm text-slate-500">Expected amount: <span class="font-semibold text-slate-800">@money($payoutAmount)</span>.</p>
                @endif

                @if ($payout?->status === 'pending' && $summary['due'] > 0)
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Clear your outstanding contributions before the payout is released.</p>
                @endif
            </x-card>

            <x-card title="Cycle details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Frequency</dt>
                        <dd class="font-medium text-slate-800">{{ ucfirst($cycle->frequency) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Rotation</dt>
                        <dd class="font-medium text-slate-800">{{ ucfirst($cycle->rotation_type) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Participants</dt>
                        <dd class="font-medium text-slate-800">{{ $cycle->participants()->count() }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Penalty</dt>
                        <dd class="font-medium text-slate-800">@bps($cycle->penalty_rate_bps) after {{ $cycle->penalty_grace_days }} day(s)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Pool target</dt>
                        <dd class="font-medium text-slate-800">@money($cycle->expectedPool())</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Keeping up to date">
                <p class="text-sm text-slate-600">You can now pay contributions yourself from your wallet or through M-Pesa. Treasurers can still record cash and bank payments. Reminder dates and messages are configured by your administrator under <span class="font-semibold text-slate-800">Notifications → Date reminders</span>.</p>
            </x-card>
        </div>
    </div>
@endsection
