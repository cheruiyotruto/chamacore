@extends('layouts.platform')

@section('title', 'Groups')

@section('content')
    @php
        $statusOptions = collect($statuses)->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all();
        $subscriptionOptions = collect($subscriptionStatuses)->mapWithKeys(fn ($status) => [$status => ucfirst(str_replace('_', ' ', $status))])->all();
        $pending = $groups->getCollection()->where('status', 'pending')->count();
        $suspended = $groups->getCollection()->where('status', 'suspended')->count();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Groups</h1>
            <p class="mt-1 text-sm text-slate-500">{{ number_format($groups->total()) }} group(s) across the platform.</p>
        </div>
        <a href="{{ route('platform.dashboard') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Overview
        </a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Groups" :value="number_format($groups->total())" hint="Across all pages" />
        <x-stat label="Pending on this page" :value="number_format($pending)" :hint="$pending > 0 ? 'Awaiting approval' : 'Nothing pending'" :tone="$pending > 0 ? 'warning' : 'brand'" />
        <x-stat label="Suspended on this page" :value="number_format($suspended)" :hint="$suspended > 0 ? 'Access blocked' : 'No suspensions'" :tone="$suspended > 0 ? 'danger' : 'neutral'" />
        <x-stat label="Filters" :value="request()->filled('status') || request()->filled('subscription_status') || request()->filled('search') ? 'Active' : 'None'" hint="Search, status and billing" tone="info" />
    </div>

    <x-card :padding="false" class="mt-6">
        <form method="GET" action="{{ route('platform.groups.index') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <div class="lg:col-span-2">
                <x-input name="search" label="Search" placeholder="Name, slug, email, phone or registration" :value="request('search')" />
            </div>
            <x-select name="status" label="Group status" placeholder="All statuses" :options="$statusOptions" :value="request('status')" />
            <x-select name="subscription_status" label="Billing" placeholder="All billing states" :options="$subscriptionOptions" :value="request('subscription_status')" />
            <x-select name="platform_mode" label="Platform control" placeholder="All modes" :options="['managed' => 'Platform-managed', 'self' => 'Self-managed', 'oversight' => 'Oversight', 'full' => 'Full control']" :value="request('platform_mode')" />
            <div class="flex items-end gap-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Filter
                </button>
                <a href="{{ route('platform.groups.index') }}" class="rounded-lg px-2 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if ($groups->isEmpty())
            <x-empty title="No groups found" message="Adjust the filters or wait for new group applications." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Group</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Platform control</th>
                            <th class="px-4 py-3">Contact</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Billing</th>
                            <th class="px-4 py-3">Plan</th>
                            <th class="px-4 py-3 text-right">Members</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($groups as $group)
                            @php $latestSubscription = $group->subscriptions->first(); @endphp
                            <tr class="hover:bg-slate-50">
                                <td class="max-w-xs px-4 py-3">
                                    <a href="{{ route('platform.groups.show', $group) }}" class="block truncate font-medium text-slate-800 hover:text-brand-700">{{ $group->name }}</a>
                                    <p class="truncate text-xs text-slate-400">{{ $group->slug }}{{ $group->registration_no ? ' · '.$group->registration_no : '' }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-slate-700">{{ $group->isBranch() ? 'Branch' : 'Organisation' }}</p>
                                    <p class="text-xs text-slate-400">
                                        @if ($group->isBranch())
                                            {{ $group->branch_code }} · {{ $group->parent?->name ?? '—' }}
                                        @else
                                            {{ $group->branches_count }} branch(es)
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-slate-700">{{ $group->platformManagementLabel() }}</p>
                                    <form method="POST" action="{{ route('platform.groups.management-mode.update', $group) }}" class="mt-2 flex items-center gap-1.5">
                                        @csrf
                                        @method('PUT')
                                        <select name="platform_management_mode" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700">
                                            @foreach ($platformModes as $value => $label)
                                                <option value="{{ $value }}" @selected($group->platform_management_mode === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <button class="rounded-lg bg-slate-900 px-2 py-1 text-xs font-semibold text-white hover:bg-slate-800">Set</button>
                                    </form>
                                </td>
                                <td class="max-w-xs px-4 py-3">
                                    <p class="truncate text-slate-700">{{ $group->email ?: '—' }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ $group->phone ?: 'No phone' }}{{ $group->county ? ' · '.$group->county : '' }}</p>
                                </td>
                                <td class="px-4 py-3"><x-badge :status="$group->status" /></td>
                                <td class="px-4 py-3"><x-badge :status="$group->subscription_status" /></td>
                                <td class="px-4 py-3">
                                    <p class="text-slate-700">{{ $latestSubscription?->plan?->name ?? '—' }}</p>
                                    <p class="text-xs text-slate-400">
                                        @if ($latestSubscription?->ends_at)
                                            Ends {{ $latestSubscription->ends_at->format('d M Y') }}
                                        @else
                                            No renewal date
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right text-slate-700">{{ number_format($group->members_count) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $group->created_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('platform.groups.show', $group) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $groups->links() }}</div>
        @endif
    </x-card>
@endsection
