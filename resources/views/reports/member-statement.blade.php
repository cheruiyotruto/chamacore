@extends('layouts.app')

@section('title', 'Member statement')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canExport = $ctx->hasPermission('reports.export');
        $rows = collect($rows);
        $totalDebit = (int) $rows->sum('debit');
        $totalCredit = (int) $rows->sum('credit');
        $memberOptions = $members->mapWithKeys(fn ($item) => [$item->id => $item->member_no.' · '.$item->full_name])->all();
        $running = 0;
    @endphp

    <x-page-header title="Member statement" :subtitle="$member ? $member->full_name.' · '.$from.' to '.$to : 'Select a member to view their statement'">
        <a href="{{ route('reports.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All reports
        </a>
        @if ($canExport && $member)
            <a href="{{ route('reports.export', ['type' => 'member_statement', 'member_id' => $member->id, 'from' => $from, 'to' => $to]) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="download" class="h-4 w-4" /> Export CSV
            </a>
        @endif
    </x-page-header>

    <x-card :padding="false">
        <form method="GET" action="{{ route('reports.member-statement') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-select name="member_id" label="Member" placeholder="Select a member" :options="$memberOptions" :value="$member?->id" required />
            </div>
            <x-input name="from" type="date" label="From" :value="$from" />
            <x-input name="to" type="date" label="To" :value="$to" />
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Generate
                </button>
                <a href="{{ route('reports.member-statement') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if (! $member)
            <x-empty title="No member selected" message="Choose a member to consolidate their savings, wallet and loan activity." class="m-4" />
        @else
            <div class="grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-stat label="Debits" :value="\App\Support\Money::format($totalDebit)" hint="Outflows and repayments" tone="warning" />
                <x-stat label="Credits" :value="\App\Support\Money::format($totalCredit)" hint="Inflows and deposits" tone="info" />
                <x-stat label="Net movement" :value="\App\Support\Money::format($totalCredit - $totalDebit)" :hint="$totalCredit >= $totalDebit ? 'Net inflow' : 'Net outflow'" :tone="$totalCredit >= $totalDebit ? 'brand' : 'danger'" />
                <x-stat label="Entries" :value="number_format($rows->count())" hint="In the selected period" tone="neutral" />
            </div>

            @if ($rows->isEmpty())
                <x-empty title="No activity" message="This member has no savings, wallet or loan movements in the selected period." class="m-4" />
            @else
                <div class="overflow-x-auto border-t border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Description</th>
                                <th class="px-4 py-3">Reference</th>
                                <th class="px-4 py-3 text-right">Debit</th>
                                <th class="px-4 py-3 text-right">Credit</th>
                                <th class="px-4 py-3 text-right">Running</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($rows as $row)
                                @php $running += ($row['credit'] ?? 0) - ($row['debit'] ?? 0); @endphp
                                <tr class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">
                                        {{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') : '—' }}
                                    </td>
                                    <td class="px-4 py-2.5"><x-badge :status="$row['type']" :label="$row['type']" /></td>
                                    <td class="max-w-md px-4 py-2.5 text-slate-700">{{ $row['description'] ?: '—' }}</td>
                                    <td class="px-4 py-2.5 font-mono text-xs text-slate-500">{{ $row['reference'] ?: '—' }}</td>
                                    <td class="px-4 py-2.5 text-right {{ ($row['debit'] ?? 0) > 0 ? 'font-medium text-rose-700' : 'text-slate-400' }}">{{ ($row['debit'] ?? 0) > 0 ? \App\Support\Money::format($row['debit']) : '—' }}</td>
                                    <td class="px-4 py-2.5 text-right {{ ($row['credit'] ?? 0) > 0 ? 'font-medium text-emerald-700' : 'text-slate-400' }}">{{ ($row['credit'] ?? 0) > 0 ? \App\Support\Money::format($row['credit']) : '—' }}</td>
                                    <td class="px-4 py-2.5 text-right font-medium {{ $running < 0 ? 'text-rose-700' : 'text-slate-900' }}">@money($running)</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-slate-50">
                            <tr>
                                <td colspan="4" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Totals</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($totalDebit)</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($totalCredit)</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($running)</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        @endif
    </x-card>
@endsection
