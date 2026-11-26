@extends('layouts.app')

@section('title', 'Teller session #'.$session->id)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $expected = $session->expectedCash();
        $variance = $session->counted_cash === null ? null : (int) $session->counted_cash - $expected;
        $cashEntries = $session->entries->where('method', 'cash');
    @endphp

    <x-page-header :title="'Till session #'.$session->id" :subtitle="($session->teller?->name ?? 'Unknown teller').' · opened '.($session->opened_at?->format('d M Y H:i') ?? '—').($session->closed_at ? ' · closed '.$session->closed_at->format('d M Y H:i') : ' · still open')">
        <a href="{{ route('teller.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Teller desk
        </a>
    </x-page-header>

    @if ($errors->has('teller'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $errors->first('teller') }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <x-badge :status="$session->status" :label="$session->isOpen() ? 'Open till' : 'Closed till'" />
        @if ($session->isOpen())
            <span class="text-sm text-slate-500">Count the physical cash and close the till when your shift ends.</span>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Opening float" :value="\App\Support\Money::format($session->opening_float)" hint="Cash at start of shift" />
        <x-stat label="Cash in" :value="\App\Support\Money::format($session->cashIn())" :hint="$cashEntries->where('direction', 'in')->count().' cash receipt(s)'" tone="info" />
        <x-stat label="Cash out" :value="\App\Support\Money::format($session->cashOut())" :hint="$cashEntries->where('direction', 'out')->count().' cash payout(s)'" tone="warning" />
        <x-stat label="Expected cash" :value="\App\Support\Money::format($expected)" hint="Opening float + cash in − cash out" tone="neutral" />
    </div>

    @if (! $session->isOpen())
        <div class="grid gap-4 sm:grid-cols-3">
            <x-stat label="Counted cash" :value="\App\Support\Money::format($session->counted_cash ?? 0)" hint="Physical drawer count" />
            <x-stat label="Variance" :value="\App\Support\Money::format($variance ?? 0)" :hint="$variance === 0 ? 'Till balanced' : ($variance > 0 ? 'Overage' : 'Shortage')" :tone="$variance === 0 ? 'neutral' : ($variance > 0 ? 'info' : 'danger')" />
            <x-stat label="Entries" :value="$session->entries->count()" hint="Transactions in this shift" />
        </div>

        @if ($session->closing_notes)
            <x-card title="Closing notes">
                <p class="text-sm text-slate-700">{{ $session->closing_notes }}</p>
            </x-card>
        @endif
    @elseif ($can('teller.operate') || $can('teller.reconcile'))
        <x-card title="Close this till" subtitle="Record the counted cash. The variance is stored on the session for audit.">
            <form method="POST" action="{{ route('teller.sessions.close', $session) }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-input name="counted_cash" type="number" step="0.01" min="0" label="Counted cash" required placeholder="0.00" help="Expected: {{ \App\Support\Money::format($expected) }}" />
                <x-input name="closing_notes" label="Closing notes" placeholder="Optional explanation for any variance" />
                <div class="sm:col-span-2">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                        <x-icon name="check" class="h-4 w-4" /> Close till
                    </button>
                </div>
            </form>
        </x-card>
    @endif

    <x-card title="Session entries" :subtitle="$session->entries->count().' transaction(s)'" :padding="false">
        @if ($session->entries->isEmpty())
            <x-empty title="No transactions in this session" message="Entries posted at this till will appear here." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Time</th>
                            <th class="px-4 py-2.5">Receipt</th>
                            <th class="px-4 py-2.5">Member</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Method</th>
                            <th class="px-4 py-2.5 text-right">Amount</th>
                            <th class="px-4 py-2.5 text-right">Charge</th>
                            <th class="px-4 py-2.5">Journal</th>
                            <th class="px-4 py-2.5 text-right">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($session->entries->sortByDesc('created_at') as $entry)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $entry->created_at?->format('d M Y H:i') }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-800">{{ $entry->receipt_no }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $entry->member?->full_name ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <p class="text-slate-700">{{ $entry->categoryLabel() }}</p>
                                    <p class="text-xs text-slate-400">{{ $entry->directionLabel() }}</p>
                                </td>
                                <td class="px-4 py-2.5 capitalize text-slate-600">
                                    {{ $entry->method }}
                                    @if ($entry->isCheque())
                                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">{{ $entry->statusLabel() }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold {{ $entry->direction === 'in' ? 'text-emerald-600' : 'text-rose-600' }}">
                                    {{ $entry->direction === 'in' ? '+' : '−' }}@money($entry->amount)
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-600">
                                    {{ $entry->charge_amount > 0 ? \App\Support\Money::format($entry->charge_amount) : '—' }}
                                </td>
                                <td class="px-4 py-2.5">
                                    @if ($entry->journal_entry_id && $can('accounting.view'))
                                        <a href="{{ route('accounting.journals.show', $entry->journal_entry_id) }}" class="text-xs font-medium text-brand-700 hover:text-brand-800">
                                            {{ $entry->journalEntry?->entry_no ?? 'View' }}
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-400">{{ $entry->journalEntry?->entry_no ?? '—' }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('teller.entries.receipt', $entry) }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800">Print</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection
