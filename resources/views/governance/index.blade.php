@extends('layouts.app')

@section('title', 'Governance')

@section('content')
    @php
        $policyTypeOptions = collect($policyTypes)->mapWithKeys(fn ($type) => [$type => ucfirst(str_replace('_', ' ', $type))])->all();
        $statusOptions = ['draft' => 'Draft', 'active' => 'Active', 'archived' => 'Archived'];
        $meetingOptions = $meetings->mapWithKeys(fn ($meeting) => [$meeting->id => ($meeting->title ?? 'Meeting').' · '.($meeting->scheduled_at?->format('d M Y') ?? '—')])->all();
        $tabs = ['policies' => 'Policies', 'resolutions' => 'Resolutions', 'meetings' => 'Meetings'];
    @endphp

    <x-page-header title="Governance" subtitle="Policies, resolutions and meeting records.">
        @if ($canManage)
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> New policy
                </summary>
                <form method="POST" action="{{ route('governance.policies.store') }}" class="absolute right-0 z-20 mt-2 w-96 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Record a policy</p>
                    <x-input name="title" label="Title" required />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-select name="type" label="Type" :options="$policyTypeOptions" required />
                        <x-input name="version" label="Version" placeholder="1.0" required />
                        <x-input name="effective_date" type="date" label="Effective date" :value="now()" required />
                        <x-select name="status" label="Status" :options="$statusOptions" required />
                    </div>
                    <x-textarea name="content" label="Content" rows="5" required />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save policy</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div x-data="{ tab: 'policies' }" class="space-y-5">
        <div class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
            @foreach ($tabs as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50'" class="rounded-lg px-3.5 py-2 text-sm font-semibold transition">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div x-show="tab === 'policies'" x-cloak class="space-y-5">
            <x-card title="Policies" :subtitle="$policies->total().' record(s)'" :padding="false">
                @if ($policies->isEmpty())
                    <x-empty title="No policies" message="Record the constitution, bylaws and codes of conduct here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($policies as $policy)
                            <details class="group">
                                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900">{{ $policy->title }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ ucfirst(str_replace('_', ' ', $policy->type)) }} · v{{ $policy->version }} ·
                                            effective {{ $policy->effective_date?->format('d M Y') ?? '—' }}
                                        </p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <x-badge :status="$policy->status" />
                                        <x-icon name="arrow-right" class="h-4 w-4 text-slate-400 transition group-open:rotate-90" />
                                    </div>
                                </summary>
                                <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-4">
                                    <p class="whitespace-pre-line text-sm text-slate-700">{{ $policy->content }}</p>

                                    @if ($canManage)
                                        <details class="mt-4">
                                            <summary class="cursor-pointer list-none text-sm font-semibold text-brand-700 hover:text-brand-800">Edit policy</summary>
                                            <form method="POST" action="{{ route('governance.policies.update', $policy) }}" class="mt-3 space-y-3 rounded-lg border border-slate-200 bg-white p-3">
                                                @csrf
                                                @method('PUT')
                                                <x-input name="title" label="Title" :value="$policy->title" required />
                                                <div class="grid gap-3 sm:grid-cols-2">
                                                    <x-select name="type" label="Type" :options="$policyTypeOptions" :value="$policy->type" required />
                                                    <x-input name="version" label="Version" :value="$policy->version" required />
                                                    <x-input name="effective_date" type="date" label="Effective date" :value="$policy->effective_date" required />
                                                    <x-select name="status" label="Status" :options="$statusOptions" :value="$policy->status" required />
                                                </div>
                                                <x-textarea name="content" label="Content" rows="6" :value="$policy->content" required />
                                                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save changes</button>
                                            </form>
                                        </details>
                                    @endif
                                </div>
                            </details>
                        @endforeach
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $policies->links() }}</div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'resolutions'" x-cloak class="space-y-5">
            @if ($canManage)
                <x-card title="Open a resolution" subtitle="Members vote through the portal; results are computed when the resolution closes.">
                    <form method="POST" action="{{ route('governance.resolutions.store') }}" class="space-y-3">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-input name="title" label="Title" required />
                            <x-select name="meeting_id" label="Meeting" placeholder="Not linked to a meeting" :options="$meetingOptions" />
                        </div>
                        <x-textarea name="description" label="Motion" rows="3" required />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-input name="voting_opens_at" type="datetime-local" label="Voting opens" :value="now()" />
                            <x-input name="voting_closes_at" type="datetime-local" label="Voting closes" />
                        </div>
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Open resolution</button>
                    </form>
                </x-card>
            @endif

            <x-card title="Resolutions" :subtitle="$resolutions->total().' record(s)'" :padding="false">
                @if ($resolutions->isEmpty())
                    <x-empty title="No resolutions" message="Resolutions raised in meetings appear here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($resolutions as $resolution)
                            <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900">{{ $resolution->title }}</p>
                                    <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ $resolution->description }}</p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        {{ $resolution->meeting?->title ?? 'No meeting' }} ·
                                        opened {{ $resolution->voting_opens_at?->format('d M Y H:i') ?? '—' }}
                                        @if ($resolution->voting_closes_at)
                                            · closes {{ $resolution->voting_closes_at->format('d M Y H:i') }}
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <div class="flex items-center gap-1.5 text-xs">
                                        <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700">{{ $resolution->votes_for }} for</span>
                                        <span class="rounded bg-rose-50 px-1.5 py-0.5 font-medium text-rose-700">{{ $resolution->votes_against }} against</span>
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 font-medium text-slate-600">{{ $resolution->votes_abstain }} abstain</span>
                                    </div>
                                    <x-badge :status="$resolution->status" />
                                    @if ($canManage && $resolution->status === 'open')
                                        <form method="POST" action="{{ route('governance.resolutions.close', $resolution) }}" onsubmit="return confirm('Close voting and record the result?');">
                                            @csrf
                                            <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Close voting</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $resolutions->links() }}</div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'meetings'" x-cloak>
            <x-card title="Recent meetings" :subtitle="$meetings->count().' meeting(s)'" :padding="false">
                @if ($meetings->isEmpty())
                    <x-empty title="No meetings" message="Schedule a meeting to record attendance and minutes." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($meetings as $meeting)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <a href="{{ route('meetings.show', $meeting) }}" class="text-sm font-semibold text-slate-900 hover:text-brand-700">{{ $meeting->title }}</a>
                                    <p class="text-xs text-slate-500">
                                        {{ $meeting->scheduled_at?->format('d M Y H:i') ?? '—' }}
                                        {{ $meeting->location ? ' · '.$meeting->location : '' }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs text-slate-500">{{ $meeting->presentCount() }} present</span>
                                    <x-badge :status="$meeting->status" />
                                    <a href="{{ route('meetings.show', $meeting) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>
@endsection
