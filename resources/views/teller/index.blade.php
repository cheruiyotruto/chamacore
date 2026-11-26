@extends('layouts.app')

@section('title', 'Teller desk')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
    @endphp

    <x-page-header title="Teller desk" subtitle="Serve members, issue receipts and reconcile your till at {{ $group->name }}.">
        <a href="{{ route('teller.cheques.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="receipt" class="h-4 w-4" /> Cheque clearance
            @if ($pendingCheques > 0)
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">{{ $pendingCheques }}</span>
            @endif
        </a>
        @if ($session)
            <a href="{{ route('teller.sessions.show', $session) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="receipt" class="h-4 w-4" /> Till summary
            </a>
        @endif
    </x-page-header>

    @if ($errors->has('teller'))
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $errors->first('teller') }}
        </div>
    @endif

    @if (! $session)
        @if ($can('teller.operate'))
            <x-card title="Open your till" subtitle="Enter the cash you are starting with so the system can calculate the expected drawer balance at close.">
                <form method="POST" action="{{ route('teller.sessions.open') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-input name="opening_float" type="number" step="0.01" min="0" label="Opening float" required placeholder="0.00" help="Cash already in the drawer when you start." />
                    <x-input name="notes" label="Notes" placeholder="Optional handover note" />
                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                            <x-icon name="plus" class="h-4 w-4" /> Open till
                        </button>
                    </div>
                </form>
            </x-card>
        @else
            <x-empty title="No open till" message="Ask a teller or the group administrator to open a till before serving members." />
        @endif
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat label="Opening float" :value="\App\Support\Money::format($session->opening_float)" hint="Cash at start of shift" />
            <x-stat label="Cash in" :value="\App\Support\Money::format($session->cashIn())" hint="Received this shift" tone="info" />
            <x-stat label="Cash out" :value="\App\Support\Money::format($session->cashOut())" hint="Paid out this shift" tone="warning" />
            <x-stat label="Expected cash" :value="\App\Support\Money::format($session->expectedCash())" hint="Count the drawer against this" tone="neutral" />
        </div>

        <x-card title="Find a member" subtitle="Search by member number, name, phone or email, then open the member sheet to post a transaction.">
            <form method="GET" action="{{ route('teller.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <x-input name="q" label="Member search" :value="$search" placeholder="e.g. MBR-0001, Jane, 0712…" autofocus />
                </div>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Search
                </button>
                @if ($search !== '')
                    <a href="{{ route('teller.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Clear</a>
                @endif
            </form>

            @if ($search !== '' && mb_strlen($search) < 2)
                <p class="mt-3 text-sm text-slate-500">Type at least two characters to search.</p>
            @elseif ($search !== '')
                @if ($members->isEmpty())
                    <x-empty title="No members found" message="Try a different name, member number or phone." class="mt-4" />
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Member</th>
                                    <th class="px-4 py-2.5">Phone</th>
                                    <th class="px-4 py-2.5">Status</th>
                                    <th class="px-4 py-2.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($members as $member)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5">
                                            <p class="font-medium text-slate-800">{{ $member->full_name }}</p>
                                            <p class="text-xs text-slate-400">{{ $member->member_no }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $member->phone ?: '—' }}</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$member->status" /></td>
                                        <td class="px-4 py-2.5 text-right">
                                            <a href="{{ route('teller.member', $member) }}" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-800">
                                                Open desk <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </x-card>

        @if ($can('teller.reconcile') || $can('teller.operate'))
            <x-card title="Close your till" subtitle="Count the physical cash in the drawer and record it before ending your shift.">
                <form method="POST" action="{{ route('teller.sessions.close', $session) }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-input name="counted_cash" type="number" step="0.01" min="0" label="Counted cash" required placeholder="0.00" help="Expected: {{ \App\Support\Money::format($session->expectedCash()) }}" />
                    <x-input name="closing_notes" label="Closing notes" placeholder="Optional explanation for any variance" />
                    <div class="sm:col-span-2">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            <x-icon name="check" class="h-4 w-4" /> Close till
                        </button>
                    </div>
                </form>
            </x-card>
        @endif
    @endif

    <x-card title="Recent teller transactions" subtitle="Every cash-desk entry is receipted and linked to the ledger." :padding="false">
        @if ($recentEntries->isEmpty())
            <x-empty title="No teller transactions yet" message="Posted transactions will appear here." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Receipt</th>
                            <th class="px-4 py-2.5">Member</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Method</th>
                            <th class="px-4 py-2.5 text-right">Amount</th>
                            <th class="px-4 py-2.5 text-right">Charge</th>
                            <th class="px-4 py-2.5">Teller</th>
                            <th class="px-4 py-2.5 text-right">Receipt</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($recentEntries as $entry)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-800">{{ $entry->receipt_no }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $entry->member?->full_name ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <p class="text-slate-700">{{ $entry->categoryLabel() }}</p>
                                    <p class="text-xs text-slate-400">{{ $entry->created_at?->format('d M Y H:i') }}</p>
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
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-600">{{ $entry->charge_amount > 0 ? \App\Support\Money::format($entry->charge_amount) : '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $entry->session?->teller?->name ?? '—' }}</td>
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

    @if ($closedSessions->isNotEmpty())
        <x-card title="Recent till closes" subtitle="Expected versus counted cash for recent shifts." :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Teller</th>
                            <th class="px-4 py-2.5">Closed</th>
                            <th class="px-4 py-2.5 text-right">Expected</th>
                            <th class="px-4 py-2.5 text-right">Counted</th>
                            <th class="px-4 py-2.5 text-right">Variance</th>
                            <th class="px-4 py-2.5 text-right">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($closedSessions as $closed)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5 text-slate-700">{{ $closed->teller?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $closed->closed_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-700">@money($closed->expected_cash)</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-slate-700">@money($closed->counted_cash ?? 0)</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold {{ $closed->variance === 0 ? 'text-slate-700' : ($closed->variance > 0 ? 'text-sky-600' : 'text-rose-600') }}">
                                    {{ $closed->variance === 0 ? 'Balanced' : ($closed->variance > 0 ? '+' : '−') }}@if ($closed->variance !== 0)@money(abs($closed->variance))@endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    <a href="{{ route('teller.sessions.show', $closed) }}" class="text-xs font-semibold text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
@endsection
