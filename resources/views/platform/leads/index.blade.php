@extends('layouts.platform')

@section('title', 'Access requests')

@section('content')
    @php
        $tones = [
            'new' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'contacted' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'qualified' => 'bg-violet-50 text-violet-700 ring-violet-200',
            'converted' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'lost' => 'bg-slate-100 text-slate-600 ring-slate-200',
        ];
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Access requests</h1>
        <p class="mt-1 text-sm text-slate-500">Organisations that asked for a demo, pricing or help getting started.</p>
    </div>

    <x-card :padding="false">
        @if ($leads->isEmpty())
            <x-empty title="No requests yet" message="Requests submitted on the public website will appear here." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Organisation</th>
                            <th class="px-4 py-3">Interest</th>
                            <th class="px-4 py-3">Size</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Received</th>
                            <th class="px-4 py-3">Update</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($leads as $lead)
                            <tr class="align-top hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900">{{ $lead->organisation }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $lead->name }}</p>
                                    <a href="mailto:{{ $lead->email }}" class="mt-1 block text-xs text-brand-700 hover:underline">{{ $lead->email }}</a>
                                    @if ($lead->phone)
                                        <a href="tel:{{ $lead->phone }}" class="text-xs text-slate-500 hover:text-brand-700">{{ $lead->phone }}</a>
                                    @endif
                                    @if ($lead->message)
                                        <p class="mt-2 max-w-xs truncate text-xs text-slate-500" title="{{ $lead->message }}">{{ $lead->message }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-slate-700">{{ $lead->group_type ?: 'Not stated' }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">Plan: {{ $lead->plan ?: 'Not chosen' }}</p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                    {{ $lead->expected_members ? number_format($lead->expected_members).' members' : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $tones[$lead->status] ?? $tones['new'] }}">
                                        {{ ucfirst($lead->status) }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">
                                    {{ $lead->created_at?->format('d M Y, H:i') ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('platform.leads.update', $lead) }}" class="flex min-w-[14rem] flex-col gap-2">
                                        @csrf
                                        @method('PUT')
                                        <select name="status" class="rounded-lg border-slate-300 text-xs focus:border-brand-500 focus:ring-brand-500">
                                            @foreach ($statuses as $status)
                                                <option value="{{ $status }}" @selected($lead->status === $status)>{{ ucfirst($status) }}</option>
                                            @endforeach
                                        </select>
                                        <input name="notes" value="{{ $lead->notes }}" placeholder="Internal note" class="rounded-lg border-slate-300 text-xs focus:border-brand-500 focus:ring-brand-500">
                                        <button type="submit" class="rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Save</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <div class="mt-4">{{ $leads->links() }}</div>
@endsection
