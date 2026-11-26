@extends('layouts.public')

@section('title', 'Join '.$group->name)

@section('content')
    @php
        $status = $invitation->effectiveStatus();
        $isOpen = $status === \App\Models\MemberInvitation::STATUS_PENDING;
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Join {{ $group->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                @if ($inviter)
                    Invited by {{ $inviter->full_name }}
                @else
                    Complete the membership application below.
                @endif
                @if ($invitation->expires_at)
                    · Link expires {{ $invitation->expires_at->format('d M Y') }}
                @endif
            </p>
        </div>
        <span class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-brand-600/20">
            {{ $group->currency }} · {{ $group->county ?: 'Kenya' }}
        </span>
    </div>

    <p class="mt-4 text-sm text-slate-500">
        No invitation?
        <a href="{{ route('public.join.index') }}" class="font-semibold text-brand-700 hover:text-brand-800">Browse groups accepting applications →</a>
    </p>

    @if (! $isOpen)
        <x-card title="This invitation is not active">
            <div class="space-y-3 text-sm text-slate-600">
                @if ($status === \App\Models\MemberInvitation::STATUS_ACCEPTED)
                    <p>An application has already been submitted using this link.</p>
                    <p>Sign in to check your application status, or contact the group office if you need help.</p>
                @else
                    <p>This join link has been {{ $status }}. Ask the member who invited you to send a new invitation.</p>
                @endif

                @if ($inviter)
                    <p class="text-xs text-slate-500">
                        Invitation from {{ $inviter->full_name }}@if ($inviter->phone) · {{ $inviter->phone }}@endif
                    </p>
                @endif
            </div>
        </x-card>
    @else
        @include('join.partials.application-form', [
            'action' => route('join.store', $invitation->token),
            'formNote' => 'Your application will be reviewed by a group official before activation.',
        ])
    @endif
@endsection
