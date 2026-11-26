@extends('layouts.marketing')

@section('title', 'Apply to join '.$group->name)
@section('description', 'Submit a membership application to join '.$group->name.' on Chama Core.')

@section('content')
    <section class="relative overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-jade-700 pb-16 pt-16 sm:pt-20">

        <div class="relative mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <a href="{{ route('public.join.index') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-300 hover:text-white">
                <x-icon name="arrow-left" class="h-4 w-4" /> All groups
            </a>

            <div class="mt-6 flex flex-wrap items-start justify-between gap-5">
                <div class="text-white">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Membership application</p>
                    <h1 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Join {{ $group->name }}</h1>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-slate-300">
                        {{ $group->public_join_summary ?: 'Complete the form below. Your details are stored securely and reviewed by the group administrators before your account can access group records.' }}
                    </p>
                    @if (filled($requirements->joiningDetails()))
                        <div class="mt-3 max-w-2xl rounded-lg border border-white/15 bg-white/5 px-4 py-3 text-sm leading-6 text-slate-200 whitespace-pre-line">
                            {{ $requirements->joiningDetails() }}
                        </div>
                    @endif
                </div>
                <span class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-200">
                    {{ $group->currency }} · {{ $group->county ?: 'Kenya' }}
                </span>
            </div>

            <div class="mt-6 flex flex-wrap gap-3 text-xs font-semibold text-slate-200">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2">
                    <x-icon name="clock" class="h-4 w-4 text-jade-300" /> Pending approval
                </span>
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2">
                    <x-icon name="shield" class="h-4 w-4 text-jade-300" /> Private EDMS storage
                </span>
            </div>
        </div>
    </section>

    <section class="bg-slate-50 py-12 sm:py-16">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            @if (session('error') || $errors->any())
                <div class="mb-5 rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-sm text-coral-600">
                    <p class="font-bold">Please check the highlighted fields.</p>
                    @if (session('error'))
                        <p class="mt-1">{{ session('error') }}</p>
                    @endif
                    @foreach ($errors->all() as $error)
                        <p class="mt-1 text-xs">• {{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @include('join.partials.application-form', [
                'action' => $action,
                'invitation' => null,
                'formNote' => 'Your application stays pending until a super admin or group official approves it.',
                'submitLabel' => 'Submit membership application',
            ])
        </div>
    </section>
@endsection
