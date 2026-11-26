@extends('layouts.app')

@section('title', 'Two-factor authentication')

@section('content')
    <div class="mx-auto max-w-2xl space-y-5">
        <x-page-header title="Two-factor authentication" subtitle="Protect approvals and disbursements with a time-based code." />

        <x-card title="1. Add the account to your authenticator app">
            <p class="text-sm text-slate-600">
                Open Google Authenticator, Authy or any TOTP-compatible app and add the key below.
            </p>

            <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Setup key</p>
                <p class="mt-1 break-all font-mono text-sm font-semibold text-slate-900">{{ $secret ?: 'Not generated yet' }}</p>
                @if ($uri)
                    <p class="mt-3 break-all text-xs text-slate-500">{{ $uri }}</p>
                @endif
            </div>
        </x-card>

        <x-card title="2. Confirm a code">
            <form method="POST" action="{{ route('mfa.enable') }}" class="space-y-4">
                @csrf
                <x-input name="code" label="Six-digit code" inputmode="numeric" maxlength="6" required help="Codes rotate every 30 seconds." />
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Enable two-factor authentication</button>
            </form>
        </x-card>

        @if (auth()->user()->mfa_enabled)
            <x-card title="Disable two-factor authentication">
                <p class="text-sm text-slate-600">Disabling reduces the protection on approvals and disbursements.</p>
                <form method="POST" action="{{ route('mfa.disable') }}" class="mt-4 space-y-4">
                    @csrf
                    @method('DELETE')
                    <x-input name="code" label="Six-digit code" inputmode="numeric" maxlength="6" required help="Enter a current code from your authenticator app." />
                    <x-input name="password" type="password" label="Password" autocomplete="current-password" required />
                    <button type="submit" class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Disable</button>
                </form>
            </x-card>
        @endif
    </div>
@endsection
