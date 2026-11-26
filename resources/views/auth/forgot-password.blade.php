@extends('layouts.guest')

@section('title', 'Reset password')

@section('content')
    <div>
        <h2 class="text-xl font-semibold tracking-tight text-slate-900">Reset your password</h2>
        <p class="mt-1 text-sm text-slate-500">Enter your email address and we will send you a reset link.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <x-input name="email" type="email" label="Email address" :value="old('email')" required autofocus autocomplete="username" />
        <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">Email reset link</button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        <a href="{{ route('login') }}" class="font-medium text-brand-700 hover:text-brand-800">Back to sign in</a>
    </p>
@endsection
