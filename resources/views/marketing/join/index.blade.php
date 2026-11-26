@extends('layouts.marketing')

@section('title', 'Join a group')
@section('description', 'Browse organisations that are accepting new member applications on Chama Core and apply to join one.')

@section('content')
    <section class="relative overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-ocean-700 pb-20 pt-16 sm:pt-20">

        <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl text-white">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Member sign-up</p>
                <h1 class="mt-5 text-4xl font-extrabold tracking-tight sm:text-5xl">Find your group and apply to join.</h1>
                <p class="mt-5 text-base leading-7 text-slate-300">
                    These organisations have asked us to make their membership applications available here. Submit your details, and the group will review your application before activating your account.
                </p>
            </div>

            <div class="mt-8 flex flex-wrap gap-3">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-200">
                    <x-icon name="shield" class="h-4 w-4 text-jade-300" /> Admin-approved groups only
                </span>
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-200">
                    <x-icon name="clock" class="h-4 w-4 text-jade-300" /> Applications reviewed before access
                </span>
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-200">
                    <x-icon name="users" class="h-4 w-4 text-jade-300" /> Your records stay inside your group
                </span>
            </div>

            <form id="invitation" method="GET" action="{{ route('public.join.index') }}" class="mt-8 max-w-2xl rounded-2xl border border-white/15 bg-white/10 p-5 backdrop-blur">
                <label for="invitation-token" class="text-sm font-bold text-white">Have an invitation link?</label>
                <p class="mt-1 text-xs leading-5 text-slate-300">Paste the link your group sent you, or the invitation code, to open your personal application.</p>
                <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                    <input id="invitation-token" name="invitation" type="text" autocomplete="off" placeholder="Paste invitation link or code" class="w-full flex-1 rounded-xl border border-white/20 bg-white/10 px-4 py-2.5 text-sm text-white placeholder-slate-400 focus:border-jade-300 focus:outline-none focus:ring-2 focus:ring-jade-300/40">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-jade-400 px-5 py-2.5 text-sm font-bold text-ink-950 transition hover:bg-jade-300">
                        Use invitation <x-icon name="arrow-right" class="h-4 w-4" />
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="bg-slate-50 py-16 sm:py-20">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-extrabold tracking-tight text-ink-900">Groups accepting applications</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ $groups->count() }} {{ $groups->count() === 1 ? 'group is' : 'groups are' }} currently open.</p>
                </div>
                <a href="{{ route('marketing.contact') }}" class="text-sm font-bold text-ocean-700 hover:text-ocean-800">
                    Represent a group? Request access →
                </a>
            </div>

            @if ($groups->isEmpty())
                <div class="mt-8 rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-white text-ocean-700">
                        <x-icon name="users" class="h-6 w-6" />
                    </span>
                    <h3 class="mt-4 text-lg font-bold text-ink-900">No groups are open right now</h3>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">
                        Groups appear here only after their super administrator opts them in. Ask your group office to enable public applications, or request a workspace for your own organisation.
                    </p>
                    <a href="{{ route('marketing.contact') }}" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-ocean-600 px-5 py-3 text-sm font-bold text-white hover:bg-ocean-700">
                        Request access <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </div>
            @else
                <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($groups as $group)
                        <div class="flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-card transition hover:-translate-y-1 hover:border-jade-300 hover:shadow-lift">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white text-sm font-extrabold text-ocean-700">
                                        {{ mb_substr($group->name, 0, 1) }}
                                    </span>
                                    <div>
                                        <h3 class="font-bold text-ink-900">{{ $group->name }}</h3>
                                        <p class="text-xs text-slate-500">{{ $group->county ?: 'Kenya' }} · {{ $group->currency }}</p>
                                    </div>
                                </div>
                                <span class="rounded-full bg-jade-50 px-2.5 py-1 text-[11px] font-bold text-jade-700">Accepting</span>
                            </div>

                            <p class="mt-4 flex-1 text-sm leading-6 text-slate-600">
                                {{ $group->public_join_summary ?: $group->description ?: 'This group is accepting new membership applications. Submit your details for review by the group administrators.' }}
                            </p>

                            <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 text-sm">
                                <div>
                                    <dt class="text-xs text-slate-500">Organisation no.</dt>
                                    <dd class="mt-0.5 font-semibold text-ink-900">{{ $group->registration_no ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-slate-500">Contact</dt>
                                    <dd class="mt-0.5 truncate font-semibold text-ink-900">{{ $group->email ?: $group->phone ?: '—' }}</dd>
                                </div>
                            </dl>

                            <a href="{{ route('public.join.apply', $group) }}" class="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-ocean-600 px-4 py-3 text-sm font-bold text-white transition hover:bg-ocean-700">
                                Apply to join <x-icon name="arrow-right" class="h-4 w-4" />
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @include('marketing.partials.cta')
@endsection
