@extends('layouts.app')

@section('title', $cycle->name)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $participants = $cycle->participants;
        $pendingPayouts = $cycle->payouts->where('status', 'pending');
        $paidContributions = $cycle->contributions()->where('status', 'paid')->sum('amount');
        $lateContributions = $cycle->contributions()->where('status', 'late')->count();
        $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $scheduleLabel = $cycle->frequency === 'weekly'
            ? 'Weekly'.($cycle->day_of_week ? ' on '.$dayNames[$cycle->day_of_week] : '')
            : 'Monthly'.($cycle->day_of_month ? ' on day '.$cycle->day_of_month : '');
    @endphp

    <x-page-header :title="$cycle->name" :subtitle="$scheduleLabel.' contributions of '.\App\Support\Money::format($cycle->contribution_amount).' · '.$cycle->start_date?->format('d M Y').' – '.$cycle->end_date?->format('d M Y')">
        @if ($canManage)
            <a href="{{ route('mgr.edit', $cycle) }}" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="cog" class="h-4 w-4" /> Edit cycle
            </a>
            <form method="POST" action="{{ route('mgr.mark-overdue', $cycle) }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3.5 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50">
                    <x-icon name="clock" class="h-4 w-4" /> Mark overdue
                </button>
            </form>
        @endif
        <a href="{{ route('mgr.index') }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
            <x-icon name="arrow-left" class="h-4 w-4" /> All cycles
        </a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Contribution" :value="\App\Support\Money::format($cycle->contribution_amount)" :hint="ucfirst($cycle->frequency).' per member'" />
        <x-stat label="Pool target" :value="\App\Support\Money::format($cycle->expectedPool())" :hint="$participants->count().' participant(s) × contribution'" tone="info" />
        <x-stat label="Pool held" :value="\App\Support\Money::format($poolBalance)" hint="Group merry-go-round ledger balance" tone="neutral" />
        <x-stat label="Payouts pending" :value="number_format($pendingPayouts->count())" :hint="$pendingPayouts->count() > 0 ? \App\Support\Money::format((int) $pendingPayouts->sum('amount')).' scheduled' : 'All payouts released'" :tone="$pendingPayouts->isEmpty() ? 'brand' : 'warning'" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Participants" :subtitle="$participants->count().' member(s) in rotation'" :padding="false">
                @if ($participants->isEmpty())
                    <x-empty title="No participants" class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">#</th>
                                    <th class="px-4 py-2.5">Member</th>
                                    <th class="px-4 py-2.5">Payout date</th>
                                    <th class="px-4 py-2.5 text-right">Contributed</th>
                                    <th class="px-4 py-2.5 text-right">Received</th>
                                    <th class="px-4 py-2.5">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($participants as $participant)
                                    @php $payout = $cycle->payouts->firstWhere('participant_id', $participant->id); @endphp
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5 text-slate-500">{{ $participant->position }}</td>
                                        <td class="px-4 py-2.5">
                                            <a href="{{ route('members.show', $participant->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $participant->member?->full_name ?? '—' }}</a>
                                            <p class="text-xs text-slate-400">{{ $participant->member?->member_no }}</p>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $participant->payout_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($participant->total_contributed)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($participant->total_received)</td>
                                        <td class="px-4 py-2.5">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <x-badge :status="$participant->status === 'paid' ? 'paid' : 'active'" :label="$participant->status === 'paid' ? 'Paid out' : 'Active'" />
                                                @if ($payout)
                                                    <x-badge :status="$payout->status" />
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Contributions" :subtitle="$contributions->total().' scheduled contribution(s)'" :padding="false">
                @if ($contributions->isEmpty())
                    <x-empty title="No contributions generated" class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Due</th>
                                    <th class="px-4 py-2.5">Member</th>
                                    <th class="px-4 py-2.5 text-right">Amount</th>
                                    <th class="px-4 py-2.5 text-right">Penalty</th>
                                    <th class="px-4 py-2.5">Status</th>
                                    @if ($canManage)
                                        <th class="px-4 py-2.5">Record</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($contributions as $contribution)
                                    <tr class="{{ $contribution->status === 'late' ? 'bg-amber-50/40' : 'hover:bg-slate-50' }}">
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $contribution->due_date?->format('d M Y') }}</td>
                                        <td class="px-4 py-2.5">
                                            <p class="font-medium text-slate-800">{{ $contribution->member?->full_name ?? '—' }}</p>
                                            <p class="text-xs text-slate-400">{{ $contribution->member?->member_no }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">@money($contribution->amount)</td>
                                        <td class="px-4 py-2.5 text-right text-slate-600">@money($contribution->penalty_amount)</td>
                                        <td class="px-4 py-2.5">
                                            <x-badge :status="$contribution->status" />
                                            @if ($contribution->paid_at)
                                                <p class="mt-0.5 text-xs text-slate-400">{{ $contribution->paid_at->format('d M') }}</p>
                                            @endif
                                        </td>
                                        @if ($canManage)
                                            <td class="px-4 py-2.5">
                                                @if ($contribution->status !== 'paid')
                                                    <form method="POST" action="{{ route('mgr.contributions.record', $contribution) }}" class="flex items-center gap-1.5">
                                                        @csrf
                                                        <select name="method" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700">
                                                            <option value="cash">Cash</option>
                                                            <option value="bank">Bank</option>
                                                            <option value="mpesa">M-Pesa</option>
                                                        </select>
                                                        <input type="text" name="reference" placeholder="Ref" class="w-20 rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700">
                                                        <button type="submit" class="rounded-lg bg-brand-600 px-2 py-1 text-xs font-semibold text-white hover:bg-brand-700">Record</button>
                                                    </form>
                                                @else
                                                    <span class="text-xs text-slate-400">{{ $contribution->reference ?: 'Recorded' }}</span>
                                                @endif
                                            </td>
                                        @endif
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
            <x-card title="Cycle details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$cycle->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Frequency</dt>
                        <dd class="font-medium text-slate-800">{{ ucfirst($cycle->frequency) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Collection day</dt>
                        <dd class="font-medium text-slate-800">
                            @if ($cycle->frequency === 'weekly')
                                {{ $cycle->day_of_week ? $dayNames[$cycle->day_of_week] : 'Start date weekday' }}
                            @else
                                {{ $cycle->day_of_month ? 'Day '.$cycle->day_of_month : 'Start date' }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Rotation</dt>
                        <dd class="font-medium text-slate-800">{{ ucfirst($cycle->rotation_type) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Penalty</dt>
                        <dd class="font-medium text-slate-800">@bps($cycle->penalty_rate_bps) after {{ $cycle->penalty_grace_days }} day(s)</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Min contributions</dt>
                        <dd class="font-medium text-slate-800">{{ $cycle->payout_requires_contributions ? $cycle->min_contributions_before_payout : 'None' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Start</dt>
                        <dd class="font-medium text-slate-800">{{ $cycle->start_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">End</dt>
                        <dd class="font-medium text-slate-800">{{ $cycle->end_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Collection progress">
                <p class="text-sm text-slate-600">
                    <span class="font-semibold text-slate-900">@money((int) $paidContributions)</span> collected so far.
                </p>
                @if ($lateContributions > 0)
                    <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ $lateContributions }} contribution(s) are overdue.</p>
                @endif
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Payout per member</dt>
                        <dd class="font-medium text-slate-800">@money($cycle->contribution_amount * max(1, $participants->count()))</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Payouts released</dt>
                        <dd class="font-medium text-slate-800">{{ $cycle->payouts->where('status', 'paid')->count() }} / {{ $cycle->payouts->count() }}</dd>
                    </div>
                </dl>
                @if ($can('reports.view'))
                    <a href="{{ route('reports.mgr') }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="chart" class="h-4 w-4" /> Merry-go-round report
                    </a>
                @endif
            </x-card>

            <x-card title="Payout schedule" :padding="false">
                @if ($cycle->payouts->isEmpty())
                    <p class="p-4 text-sm text-slate-500">No payouts scheduled.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($cycle->payouts->sortBy('scheduled_date') as $payout)
                            <li class="px-4 py-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-800">{{ $payout->member?->full_name ?? '—' }}</p>
                                        <p class="text-xs text-slate-500">{{ $payout->scheduled_date?->format('d M Y') }} · @money($payout->amount)</p>
                                    </div>
                                    <x-badge :status="$payout->status" />
                                </div>
                                @if ($canManage && $payout->status === 'pending')
                                    <form method="POST" action="{{ route('mgr.payouts.pay', $payout) }}" class="mt-2 flex items-center gap-1.5">
                                        @csrf
                                        <select name="method" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700">
                                            <option value="cash">Cash</option>
                                            <option value="bank">Bank</option>
                                        </select>
                                        <button type="submit" class="rounded-lg bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Release payout</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>
    </div>
@endsection
