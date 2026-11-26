@extends('layouts.app')

@section('title', 'Organisation portal')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
    @endphp

    <x-page-header title="Organisation overview" :subtitle="($group?->name ?? 'Group').' · organisation position as at '.now()->format('d M Y H:i')">
        @if ($can('members.create'))
            <a href="{{ route('members.create') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="users" class="h-4 w-4" /> Add member
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Cash & bank" :value="\App\Support\Money::format($cashPosition)" hint="Ledger-backed position" />
        <x-stat label="Wallet float" :value="\App\Support\Money::format($walletTotal)" hint="Held on behalf of members" tone="neutral" />
        <x-stat label="Active members" :value="number_format($activeMembers)" :hint="$pendingMembers.' pending application(s)'" tone="info" />
        <x-stat label="Unsettled fines" :value="\App\Support\Money::format($pendingFines)" :hint="$pendingClaims.' welfare claim(s) pending'" tone="warning" />
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        <x-card title="Next meeting">
            @if ($nextMeeting)
                <p class="text-sm font-semibold text-slate-900">{{ $nextMeeting->title }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $nextMeeting->scheduled_at->format('l, d F Y · H:i') }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $nextMeeting->location ?: 'Venue to be confirmed' }}</p>
                <a href="{{ route('meetings.show', $nextMeeting) }}" class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:text-brand-800">View agenda <x-icon name="arrow-right" class="h-4 w-4" /></a>
            @else
                <p class="text-sm text-slate-500">No meeting is scheduled.</p>
                @if ($can('meetings.manage'))
                    <a href="{{ route('meetings.create') }}" class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-700">Schedule one <x-icon name="arrow-right" class="h-4 w-4" /></a>
                @endif
            @endif
        </x-card>

        <x-card title="Merry-go-round">
            @if ($mgrCycle)
                <p class="text-sm font-semibold text-slate-900">{{ $mgrCycle->name }}</p>
                <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                    <span>Contribution</span>
                    <span class="font-medium text-slate-800">@money($mgrCycle->contribution_amount) / {{ $mgrCycle->frequency }}</span>
                </div>
                <div class="mt-1 flex items-center justify-between text-xs text-slate-500">
                    <span>Status</span>
                    <x-badge :status="$mgrCycle->status" />
                </div>
                <a href="{{ route('mgr.show', $mgrCycle) }}" class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-700">Open cycle <x-icon name="arrow-right" class="h-4 w-4" /></a>
            @else
                <p class="text-sm text-slate-500">No active merry-go-round cycle.</p>
            @endif
        </x-card>
    </div>
@endsection
