@extends('layouts.app')

@section('title', 'My profile')

@section('content')
    @php
        $initials = $user->initials();
    @endphp

    <x-page-header title="My profile" subtitle="Account details, memberships and recent sign-in activity.">
        <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Dashboard
        </a>
    </x-page-header>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Account details">
                <div class="mb-4 flex items-center gap-4">
                    <span class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700">{{ $initials }}</span>
                    <div>
                        <p class="text-base font-semibold text-slate-900">{{ $user->name }}</p>
                        <p class="text-sm text-slate-500">{{ $user->email }}</p>
                        <div class="mt-1 flex items-center gap-2">
                            <x-badge :status="$user->status ?? 'active'" />
                            @if ($user->isSuperAdmin())
                                <x-badge status="info" label="Super admin" />
                            @endif
                            @if ($user->mfa_enabled)
                                <x-badge status="brand" label="MFA enabled" />
                            @else
                                <x-badge status="warning" label="MFA off" />
                            @endif
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('profile.update') }}" class="space-y-4 border-t border-slate-100 pt-4">
                    @csrf
                    @method('PATCH')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input name="name" label="Full name" :value="$user->name" required />
                        <x-input name="email" type="email" label="Email" :value="$user->email" required />
                        <x-input name="phone" label="Phone" :value="$user->phone" />
                    </div>
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save profile</button>
                </form>
            </x-card>

            <x-card title="Change password" subtitle="Use a strong password you do not reuse elsewhere.">
                <form method="POST" action="{{ route('profile.password') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input name="current_password" type="password" label="Current password" required />
                        <div></div>
                        <x-input name="password" type="password" label="New password" required />
                        <x-input name="password_confirmation" type="password" label="Confirm new password" required />
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Change password</button>
                </form>
            </x-card>

            <x-card title="Group memberships" :subtitle="$memberships->count().' membership(s)'" :padding="false">
                @if ($memberships->isEmpty())
                    <x-empty title="No memberships" message="You are not attached to any group yet." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($memberships as $membership)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">{{ $membership->group?->name ?? 'Group removed' }}</p>
                                    <p class="text-xs text-slate-500">{{ $membership->member_no }} · {{ $membership->role?->name ?? 'No role' }} · {{ ucfirst($membership->membership_category ?? 'member') }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-badge :status="$membership->status" />
                                    @if ($currentGroup?->id === $membership->group_id)
                                        <span class="text-xs font-medium text-brand-700">Current</span>
                                    @else
                                        <form method="POST" action="{{ route('profile.switch-group', $membership->group_id) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Switch</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Security" subtitle="Account safety at a glance.">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Email verified</dt>
                        <dd class="font-medium text-slate-800">{{ $user->email_verified_at?->format('d M Y') ?? 'Not verified' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">MFA</dt>
                        <dd><x-badge :status="$user->mfa_enabled ? 'active' : 'inactive'" :label="$user->mfa_enabled ? 'Enabled' : 'Disabled'" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Last login</dt>
                        <dd class="font-medium text-slate-800">{{ $user->last_login_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                </dl>

                @if (! $user->mfa_enabled)
                    <a href="{{ route('mfa.setup') }}" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="shield" class="h-4 w-4" /> Set up MFA
                    </a>
                @endif
            </x-card>

            <x-card title="Recent sign-ins" :subtitle="$loginActivities->count().' event(s)'" :padding="false">
                @if ($loginActivities->isEmpty())
                    <x-empty title="No activity" message="Sign-in history will appear here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($loginActivities as $activity)
                            <div class="px-4 py-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-medium {{ $activity->successful ? 'text-slate-800' : 'text-rose-700' }}">
                                        {{ $activity->successful ? 'Successful sign-in' : 'Failed attempt' }}
                                    </span>
                                    <span class="text-xs text-slate-400">{{ $activity->created_at?->format('d M H:i') ?? '—' }}</span>
                                </div>
                                <p class="text-xs text-slate-500">{{ $activity->ip_address ?: 'Unknown IP' }}</p>
                                @if ($activity->failure_reason)
                                    <p class="text-xs text-rose-600">{{ $activity->failure_reason }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>
@endsection
