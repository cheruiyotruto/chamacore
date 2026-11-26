@extends('layouts.public')

@section('title', 'Application submitted')

@section('content')
    <x-card>
        <div class="py-6 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                <x-icon name="check" class="h-7 w-7" />
            </span>

            <h1 class="mt-4 text-xl font-semibold text-slate-900">Application received</h1>
            <p class="mt-2 text-sm text-slate-600">
                Thank you{{ $member?->first_name ? ', '.$member->first_name : '' }}. Your application to join {{ $group->name }} has been submitted for review.
            </p>

            @if ($member)
                <div class="mx-auto mt-5 max-w-sm rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left text-sm">
                    <div class="flex items-center justify-between gap-3 py-1">
                        <span class="text-slate-500">Member number</span>
                        <span class="font-semibold text-slate-900">{{ $member->member_no }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-1">
                        <span class="text-slate-500">Status</span>
                        <x-badge :status="$member->status" />
                    </div>
                    <div class="flex items-center justify-between gap-3 py-1">
                        <span class="text-slate-500">Group</span>
                        <span class="font-medium text-slate-900">{{ $group->name }}</span>
                    </div>
                </div>
            @endif

            <p class="mt-5 text-sm text-slate-600">
                A group official will review your details. If you provided an email address, we have sent a link so you can set a password and check your application status.
            </p>

            @if ($inviter)
                <p class="mt-2 text-xs text-slate-500">
                    Invited by {{ $inviter->full_name }}@if ($inviter->phone) · {{ $inviter->phone }}@endif
                </p>
            @endif

            <a href="{{ route('login') }}" class="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                Go to sign in <x-icon name="arrow-right" class="h-4 w-4" />
            </a>
            <p class="mt-3">
                <a href="{{ route('public.join.index') }}" class="text-sm font-semibold text-brand-700 hover:text-brand-800">Browse other open groups →</a>
            </p>
        </div>
    </x-card>
@endsection
