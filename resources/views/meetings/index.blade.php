@extends('layouts.app')

@section('title', 'Meetings')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $canManage = $ctx->hasPermission('meetings.manage');
    @endphp

    <x-page-header title="Meetings" :subtitle="$meetings->total().' meeting(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($canManage)
            <a href="{{ route('meetings.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Schedule meeting
            </a>
        @endif
    </x-page-header>

    <x-card :padding="false">
        <form method="GET" action="{{ route('meetings.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-56">
                <x-select name="status" label="Status" placeholder="All statuses" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s => ucfirst($s)])->all()" :value="request('status')" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Filter
            </button>
            <a href="{{ route('meetings.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
        </form>

        @if ($meetings->isEmpty())
            <x-empty title="No meetings" message="Schedule a meeting to record attendance, collections and minutes." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Meeting</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Scheduled</th>
                            <th class="px-4 py-3">Location</th>
                            <th class="px-4 py-3 text-right">Attendance</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($meetings as $meeting)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('meetings.show', $meeting) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $meeting->title }}</a>
                                    @if ($meeting->agenda)
                                        <p class="max-w-md truncate text-xs text-slate-400">{{ $meeting->agenda }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3"><x-badge :status="$meeting->type" :label="strtoupper($meeting->type)" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $meeting->scheduled_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $meeting->location ?: '—' }}</td>
                                <td class="px-4 py-3 text-right text-slate-600">{{ $meeting->attendances_count }}</td>
                                <td class="px-4 py-3"><x-badge :status="$meeting->status" /></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('meetings.show', $meeting) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $meetings->links() }}</div>
        @endif
    </x-card>
@endsection
