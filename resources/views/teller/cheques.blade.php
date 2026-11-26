@extends('layouts.app')

@section('title', 'Cheque clearance')

@section('content')
    @php
        $filters = [
            '' => 'All',
            \App\Models\TellerEntry::STATUS_PENDING => 'Pending',
            \App\Models\TellerEntry::STATUS_CLEARED => 'Cleared',
            \App\Models\TellerEntry::STATUS_BOUNCED => 'Bounced',
        ];

        $badgeClasses = [
            \App\Models\TellerEntry::STATUS_PENDING => 'bg-amber-100 text-amber-800',
            \App\Models\TellerEntry::STATUS_CLEARED => 'bg-emerald-100 text-emerald-800',
            \App\Models\TellerEntry::STATUS_BOUNCED => 'bg-rose-100 text-rose-800',
            \App\Models\TellerEntry::STATUS_POSTED => 'bg-slate-100 text-slate-700',
        ];
    @endphp

    <x-page-header title="Cheque clearance" subtitle="Review cheques captured at the teller desk. Ledger entries are created only when a cheque is cleared.">
        <a href="{{ route('teller.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Teller desk
        </a>
    </x-page-header>

    @if ($errors->has('teller'))
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $errors->first('teller') }}
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="All cheques" :value="(string) $counts['all']" hint="Captured at the teller desk" />
        <x-stat label="Pending clearance" :value="(string) $counts['pending']" hint="Not yet posted to the ledger" tone="warning" />
        <x-stat label="Cleared" :value="(string) $counts['cleared']" hint="Posted to the bank counter" tone="info" />
        <x-stat label="Bounced" :value="(string) $counts['bounced']" hint="No ledger entry created" tone="neutral" />
    </div>

    <x-card title="Captured cheques" :subtitle="$cheques->total().' cheque(s) in this view'" :padding="false">
        <div class="flex flex-wrap gap-2 border-b border-slate-200 px-4 py-3">
            @foreach ($filters as $key => $label)
                <a
                    href="{{ route('teller.cheques.index', $key === '' ? [] : ['status' => $key]) }}"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $status === $key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}"
                >
                    {{ $label }}
                    @if ($key !== '' && isset($counts[$key]))
                        <span class="ml-1 opacity-70">{{ $counts[$key] }}</span>
                    @endif
                </a>
            @endforeach
        </div>

        @if ($cheques->isEmpty())
            <x-empty title="No cheques found" message="Cheques captured from a member sheet will appear here for clearance." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">Receipt</th>
                            <th class="px-4 py-2.5">Member</th>
                            <th class="px-4 py-2.5">Cheque</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5 text-right">Amount</th>
                            <th class="px-4 py-2.5">Status</th>
                            <th class="px-4 py-2.5">Captured</th>
                            <th class="px-4 py-2.5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($cheques as $cheque)
                            @php
                                $details = (array) ($cheque->cheque_details ?? []);
                                $state = $cheque->status ?: \App\Models\TellerEntry::STATUS_POSTED;
                            @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-slate-800">{{ $cheque->receipt_no }}</td>
                                <td class="px-4 py-2.5 text-slate-700">
                                    <p>{{ $cheque->member?->full_name ?? '—' }}</p>
                                    <p class="text-xs text-slate-400">{{ $cheque->member?->member_no ?? '' }}</p>
                                </td>
                                <td class="px-4 py-2.5">
                                    <p class="font-medium text-slate-700">No. {{ $details['cheque_number'] ?? '—' }}</p>
                                    <p class="text-xs text-slate-500">{{ $details['bank_name'] ?? '—' }}{{ isset($details['branch']) ? ' · '.$details['branch'] : '' }}</p>
                                    @if (isset($details['cheque_date']))
                                        <p class="text-xs text-slate-400">Dated {{ $details['cheque_date'] }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">{{ $cheque->categoryLabel() }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-semibold text-slate-800">@money($cheque->amount)</td>
                                <td class="px-4 py-2.5">
                                    <span class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold uppercase tracking-wide {{ $badgeClasses[$state] ?? 'bg-slate-100 text-slate-700' }}">
                                        {{ $cheque->statusLabel() }}
                                    </span>
                                    @if ($cheque->isCleared())
                                        <p class="mt-1 text-xs text-slate-400">{{ $cheque->cleared_at?->format('d M Y H:i') }}</p>
                                    @elseif ($cheque->isBounced())
                                        <p class="mt-1 text-xs text-slate-400">{{ $cheque->bounced_at?->format('d M Y H:i') }}</p>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">
                                    {{ $cheque->created_at?->format('d M Y H:i') }}
                                    <p class="text-xs text-slate-400">{{ $cheque->session?->teller?->name ?? '—' }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if ($cheque->canResolveCheque())
                                        <form method="POST" action="{{ route('teller.cheques.clear', $cheque) }}" class="flex flex-col items-end gap-2 sm:flex-row sm:items-center">
                                            @csrf
                                            <input
                                                type="text"
                                                name="clearance_notes"
                                                placeholder="Notes (optional)"
                                                maxlength="500"
                                                class="w-full rounded-lg border border-slate-300 px-2 py-1.5 text-xs text-slate-800 sm:w-40"
                                            >
                                            <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Clear</button>
                                            <button type="submit" formaction="{{ route('teller.cheques.bounce', $cheque) }}" class="rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Bounce</button>
                                        </form>
                                    @else
                                        <p class="text-xs text-slate-500">
                                            @if ($cheque->isCleared())
                                                Cleared by {{ $cheque->clearer?->name ?? '—' }}
                                            @elseif ($cheque->isBounced())
                                                Bounced by {{ $cheque->bouncer?->name ?? '—' }}
                                            @endif
                                        </p>
                                    @endif
                                    <a href="{{ route('teller.entries.receipt', $cheque) }}" class="mt-1 inline-block text-xs font-semibold text-brand-700 hover:text-brand-800">Receipt</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">
                {{ $cheques->links() }}
            </div>
        @endif
    </x-card>
@endsection
