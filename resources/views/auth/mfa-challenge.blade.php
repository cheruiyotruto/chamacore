@extends('layouts.guest')

@section('title', 'Two-factor verification')

@section('content')
    <div>
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Two-factor verification</h2>
        <p class="mt-1 text-sm text-slate-500">Enter the six-digit code from your authenticator app to continue.</p>
    </div>

    <form method="POST" action="{{ route('mfa.verify') }}" class="mt-6 space-y-4">
        @csrf
        <x-input name="code" label="Authentication code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus />
        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Verify</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-slate-500 hover:text-slate-700">Sign out instead</button>
    </form>
@endsection
