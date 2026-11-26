@extends('layouts.marketing')

@section('title', 'Application received')
@section('description', 'Your membership application has been received and is awaiting approval.')

@section('content')
    <section class="relative overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-jade-700 pb-24 pt-16 sm:pt-20">

        <div class="relative mx-auto max-w-3xl px-4 text-center sm:px-6 lg:px-8">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-jade-500 text-ink-950 shadow-lift">
                <x-icon name="check" class="h-8 w-8" />
            </span>

            <p class="mt-7 text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Application received</p>
            <h1 class="mt-4 text-4xl font-extrabold tracking-tight text-white sm:text-5xl">Thank you for applying to {{ $group->name }}.</h1>
            <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-300">
                Your details and documents have been saved securely. The group administrators will review your application and activate your account once it is approved.
            </p>

            @if (session('status'))
                <p class="mx-auto mt-6 max-w-2xl rounded-xl border border-jade-300/30 bg-jade-400/10 px-5 py-3 text-sm font-semibold text-jade-200">
                    {{ session('status') }}
                </p>
            @endif

            <div class="mt-9 flex flex-wrap justify-center gap-3">
                <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-xl bg-jade-500 px-6 py-3.5 text-sm font-bold text-ink-950 hover:bg-jade-400">
                    Go to sign in <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
                <a href="{{ route('public.join.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-white/20 px-6 py-3.5 text-sm font-bold text-white hover:bg-white/10">
                    Browse other groups
                </a>
            </div>

            <p class="mt-8 text-xs text-slate-400">
                A password setup link has been sent to the email address you provided. Until approval, your account cannot open group records.
            </p>
        </div>
    </section>
@endsection
