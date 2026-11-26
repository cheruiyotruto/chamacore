@extends('layouts.platform')

@section('title', 'Platform overview')

@section('content')
    @php
        $planRows = $planBreakdown->sortByDesc('subscriptions_count');
        $maxPlan = max(1, $planRows->max('subscriptions_count') ?? 1);
        $activeShare = $stats['groups'] > 0 ? (int) round(($stats['active_groups'] / $stats['groups']) * 100) : 0;
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Platform overview</h1>
            <p class="mt-1 text-sm text-slate-500">Cross-tenant health across every group on {{ config('app.name') }}.</p>
        </div>
        <a href="{{ route('platform.groups.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
            <x-icon name="users" class="h-4 w-4" /> Manage groups
        </a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Organisations" :value="number_format($stats['groups'])" :hint="$stats['active_groups'].' active · '.$stats['branches'].' branches'" />
        <x-stat label="Awaiting approval" :value="number_format($stats['pending_groups'])" :hint="$stats['pending_groups'] > 0 ? 'Review new group applications' : 'Nothing pending'" :tone="$stats['pending_groups'] > 0 ? 'warning' : 'brand'" />
        <x-stat label="Active members" :value="number_format($stats['members'])" hint="Across every active group" tone="info" />
        <x-stat label="Monthly recurring revenue" :value="\App\Support\Money::format($stats['mrr'])" :hint="$stats['active_subscriptions'].' active subscription(s)'" tone="brand" />
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Suspended groups" :value="number_format($stats['suspended_groups'])" :hint="$stats['suspended_groups'] > 0 ? 'Access is blocked for these groups' : 'No suspensions'" :tone="$stats['suspended_groups'] > 0 ? 'danger' : 'neutral'" />
        <x-stat label="Managed income" :value="\App\Support\Money::format($stats['managed_income'])" :hint="$stats['managed_groups'].' marked organisation(s)'" tone="info" />
        <x-stat label="Expiring soon" :value="number_format($expiringSubscriptions->count())" hint="Subscriptions ending in 30 days" :tone="$expiringSubscriptions->isNotEmpty() ? 'warning' : 'brand'" />
        <x-stat label="Active subscriptions" :value="number_format($stats['active_subscriptions'])" hint="Active and trialing" tone="info" />
    </div>

    <div class="mt-6 grid gap-5 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <x-card title="Newest groups" subtitle="The most recently created groups on the platform." :padding="false">
                <x-slot:actions>
                    <a href="{{ route('platform.groups.index') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        View all <x-icon name="arrow-right" class="h-3.5 w-3.5" />
                    </a>
                </x-slot:actions>

                @if ($recentGroups->isEmpty())
                    <x-empty title="No groups yet" message="Groups will appear here as they sign up." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Group</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Members</th>
                                    <th class="px-4 py-3">Created</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($recentGroups as $group)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3">
                                            <a href="{{ route('platform.groups.show', $group) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $group->name }}</a>
                                            <p class="text-xs text-slate-400">{{ $group->slug }}</p>
                                        </td>
                                        <td class="px-4 py-3"><x-badge :status="$group->status" /></td>
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
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Plan mix" subtitle="Active subscriptions per plan." :padding="false">
                @if ($planRows->isEmpty())
                    <x-empty title="No subscriptions" message="No group has an active plan yet." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($planRows as $row)
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3 text-sm">
                                    <span class="font-medium text-slate-800">{{ $row->plan?->name ?? 'Archived plan' }}</span>
                                    <span class="font-semibold text-slate-900">{{ number_format($row->subscriptions_count) }}</span>
                                </div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-brand-500" style="width: {{ max(3, (int) round(($row->subscriptions_count / $maxPlan) * 100)) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Expiring subscriptions" subtitle="Ending within 30 days" :padding="false">
                @if ($expiringSubscriptions->isEmpty())
                    <x-empty title="Nothing expiring" message="No subscription ends in the next 30 days." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($expiringSubscriptions as $subscription)
                            <div class="px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ route('platform.groups.show', $subscription->group) }}" class="block truncate text-sm font-medium text-slate-800 hover:text-brand-700">{{ $subscription->group?->name ?? 'Group removed' }}</a>
                                        <p class="text-xs text-slate-500">{{ $subscription->plan?->name ?? 'No plan' }}</p>
                                    </div>
                                    <span class="whitespace-nowrap text-xs font-medium {{ $subscription->ends_at?->isPast() ? 'text-rose-700' : 'text-amber-700' }}">
                                        {{ $subscription->ends_at?->format('d M Y') ?? '—' }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <a href="{{ route('platform.applications.index') }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
            <p class="text-sm font-semibold text-slate-900">Member applications</p>
            <p class="mt-1 text-sm text-slate-500">Approve or reject people applying to join groups.</p>
        </a>
        <a href="{{ route('platform.leads.index') }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
            <p class="text-sm font-semibold text-slate-900">Access requests</p>
            <p class="mt-1 text-sm text-slate-500">Review organisations asking to start with Chama Core.</p>
        </a>
        <a href="{{ route('platform.groups.index', ['status' => 'pending']) }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
            <p class="text-sm font-semibold text-slate-900">Approve groups</p>
            <p class="mt-1 text-sm text-slate-500">Review pending applications and activate groups.</p>
        </a>
        <a href="{{ route('platform.groups.index', ['status' => 'suspended']) }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
            <p class="text-sm font-semibold text-slate-900">Suspended groups</p>
            <p class="mt-1 text-sm text-slate-500">Reinstate access or keep accounts locked.</p>
        </a>
        <a href="{{ route('platform.revenue.index') }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
            <p class="text-sm font-semibold text-slate-900">Managed revenue</p>
            <p class="mt-1 text-sm text-slate-500">See income reported by marked organisations.</p>
        </a>
        <a href="{{ route('platform.plans.index') }}" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-300">
            <p class="text-sm font-semibold text-slate-900">Manage plans</p>
            <p class="mt-1 text-sm text-slate-500">Adjust pricing, limits and bundled modules.</p>
        </a>
    </div>
@endsection
