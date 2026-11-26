@extends('layouts.guest')

@section('title', 'Choose a new password')

@section('content')
    <div>
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Choose a new password</h2>
        <p class="mt-1 text-sm text-slate-500">Use at least eight characters, including letters and numbers.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-input name="email" type="email" label="Email address" :value="$email" required autocomplete="username" />
        <x-input name="password" type="password" label="New password" required autocomplete="new-password" />
        <x-input name="password_confirmation" type="password" label="Confirm new password" required autocomplete="new-password" />

        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Reset password</button>
    </form>
@endsection
