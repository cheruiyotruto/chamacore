@extends('layouts.app')

@section('title', 'Journal entries')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
    @endphp

    <x-page-header title="Journal entries" :subtitle="$entries->total().' entries in the general ledger'">
        @if ($can('accounting.journal'))
            <a href="{{ route('accounting.journals.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New journal
            </a>
        @endif
        <a href="{{ route('accounting.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Accounting
        </a>
    </x-page-header>

    <x-card :padding="false">
        <form method="GET" action="{{ route('accounting.journals') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-input name="search" placeholder="Entry number or description" :value="request('search')" />
            <x-input name="from" type="date" label="From" :value="request('from')" />
            <x-input name="to" type="date" label="To" :value="request('to')" />
            <div class="flex items-end gap-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Filter
                </button>
                <a href="{{ route('accounting.journals') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if ($entries->isEmpty())
            <x-empty title="No journal entries" message="Posted journals appear here in date order." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Entry</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3 text-right">Lines</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Posted by</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($entries as $entry)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('accounting.journals.show', $entry) }}" class="font-mono text-xs font-medium text-brand-700 hover:text-brand-800">{{ $entry->entry_no }}</a>
                                    @if ($entry->reversal_of_id)
                                        <span class="ml-1 rounded bg-amber-50 px-1 py-0.5 text-[10px] font-medium uppercase text-amber-700">reversal</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $entry->entry_date?->format('d M Y') ?? '—' }}</td>
                                <td class="max-w-md px-4 py-3">
                                    <p class="truncate font-medium text-slate-800">{{ $entry->description }}</p>
                                    @if ($entry->source_type)
                                        <p class="text-xs text-slate-400">{{ class_basename($entry->source_type) }} #{{ $entry->source_id }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-slate-600">{{ $entry->lines_count }}</td>
                                <td class="px-4 py-3"><x-badge :status="$entry->status" /></td>
                                <td class="px-4 py-3 text-slate-600">{{ $entry->postedBy?->name ?? 'System' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('accounting.journals.show', $entry) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $entries->links() }}</div>
        @endif
    </x-card>
@endsection
