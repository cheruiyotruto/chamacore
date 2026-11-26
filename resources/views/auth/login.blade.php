@extends('layouts.guest')

@section('title', 'Sign in')

@section('content')
    <div>
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Welcome back</h2>
        <p class="mt-1 text-sm text-slate-500">Sign in to access your group dashboard.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" class="mt-6 space-y-4">
        @csrf

        <x-input name="email" type="email" label="Email address" :value="old('email')" required autofocus autocomplete="username" />
        <x-input name="password" type="password" label="Password" required autocomplete="current-password" />

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked(old('remember'))>
                <span>Remember me</span>
            </label>
            <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Forgot password?</a>
        </div>

        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Sign in</button>
    </form>
@endsection
