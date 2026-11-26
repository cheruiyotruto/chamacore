@extends('layouts.marketing')

@section('title', 'About Chama Core')
@section('description', 'Learn why Chama Core exists: a secure, practical system that helps groups manage money, members and decisions with confidence.')

@section('content')
    <section class="relative grid-noise overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-ocean-700 pb-20 pt-16 sm:pt-20">

        <div class="relative mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            <div class="text-white">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">About the platform</p>
                <h1 class="mt-5 text-4xl font-extrabold leading-tight tracking-tight sm:text-5xl">
                    We built Chama Core because group money deserves better than guesswork.
                </h1>
                <p class="mt-6 text-base leading-7 text-slate-300">
                    Chama Core is a practical operating system for groups that collect, lend, save and distribute money together. It brings the ledger, the member portal and the committee workflow into one place so everyone can see the right thing at the right time.
                </p>
                <a href="{{ route('marketing.contact') }}" class="mt-8 inline-flex items-center gap-2 rounded-xl bg-jade-500 px-6 py-3.5 text-sm font-bold text-ink-950 hover:bg-jade-400">
                    Talk to our team
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>

            <div class="relative">
                <div class="relative rounded-3xl border border-white/10 bg-ink-950/70 p-7 shadow-lift sm:p-9">
                    <p class="text-sm font-semibold text-slate-300">The problem we start with</p>
                    <div class="mt-6 space-y-4">
                        @foreach ([
                            'A treasurer keeping balances in a notebook',
                            'A member unsure whether a payment was received',
                            'A committee waiting for a report before it can decide',
                            'A branch using a different file from head office',
                        ] as $problem)
                            <div class="flex items-start gap-3 rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-coral-500/15 text-coral-500">
                                    <x-icon name="x" class="h-3 w-3" />
                                </span>
                                <p class="text-sm text-slate-300">{{ $problem }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-6 rounded-xl border border-jade-400/20 bg-jade-400/10 px-4 py-3 text-sm font-semibold text-jade-200">
                        One workspace replaces all of them — without losing the human judgement behind the decisions.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-[0.85fr_1.15fr]">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-700">Our purpose</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">Make group finance easier to trust, not harder to hide.</h2>
                </div>
                <div class="space-y-5 text-base leading-7 text-slate-600">
                    <p>
                        Groups do not need software that adds steps. They need a system that respects how they already work: meetings, contributions, approvals, payment follow-ups and shared decisions.
                    </p>
                    <p>
                        So we designed Chama Core around a simple idea: every action should have an owner, a permission, a record and a place in the ledger. If a member pays by M-Pesa, the group sees it. If an official approves a loan, the committee can trace it. If a branch follows head office policy, the inheritance is explicit.
                    </p>
                    <p>
                        The result is not just cleaner books. It is more confident meetings, faster answers and a fairer experience for members.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section id="principles" class="scroll-mt-24 bg-slate-50 py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-700">How we work</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">Principles behind every module.</h2>
            </div>

            <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['icon' => 'shield', 'title' => 'Boundaries first', 'body' => 'Group context and member permissions are enforced before any data is shown.'],
                    ['icon' => 'book', 'title' => 'Records before reports', 'body' => 'A report is only useful when the underlying entries are complete and balanced.'],
                    ['icon' => 'clock', 'title' => 'Timely by design', 'body' => 'Due dates, reminders and status views help groups act before problems grow.'],
                    ['icon' => 'users', 'title' => 'Useful for members', 'body' => 'The member portal is part of the product, not an afterthought for officials.'],
                ] as $principle)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-card">
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-white text-ocean-700">
                            <x-icon :name="$principle['icon']" class="h-6 w-6" />
                        </span>
                        <h3 class="mt-5 text-lg font-bold text-ink-900">{{ $principle['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $principle['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid items-center gap-12 lg:grid-cols-2">
                <div class="rounded-3xl bg-gradient-to-br from-ocean-600 to-jade-600 p-8 text-white shadow-lift sm:p-10">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-100">Under the hood</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight">One shared foundation for every group.</h2>
                    <div class="mt-8 space-y-4">
                        @foreach ([
                            ['Double-entry posting', 'Savings, loans, bills, payments and payouts share one accounting spine.'],
                            ['Integer minor units', 'Money is stored in cents to avoid rounding surprises.'],
                            ['Tenant isolation', 'Every group is its own workspace with its own members and settings.'],
                            ['Audit trail', 'Important actions are recorded so decisions can be reviewed later.'],
                        ] as $foundation)
                            <div class="flex items-start gap-3 rounded-xl border border-white/15 bg-white/10 px-4 py-3.5">
                                <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-jade-300" />
                                <div>
                                    <p class="text-sm font-bold">{{ $foundation[0] }}</p>
                                    <p class="mt-0.5 text-sm text-ocean-50">{{ $foundation[1] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-700">Who we serve</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">Built to fit the way different groups operate.</h2>
                    <p class="mt-4 text-base leading-7 text-slate-600">A small chama and a multi-branch cooperative need the same trust, but not the same setup. Chama Core keeps the foundation consistent while letting configuration follow the organisation.</p>

                    <div class="mt-8 grid gap-4 sm:grid-cols-2">
                        @foreach ([
                            'Chamas and investment clubs',
                            'SACCOs and cooperatives',
                            'Welfare and burial groups',
                            'Employer and umbrella bodies',
                        ] as $groupType)
                            <div class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-ink-900">
                                <span class="h-2 w-2 rounded-full bg-jade-500"></span>
                                {{ $groupType }}
                            </div>
                        @endforeach
                    </div>

                    <a href="{{ route('marketing.features') }}" class="mt-8 inline-flex items-center gap-2 text-sm font-bold text-ocean-700 hover:text-ocean-800">
                        See how the modules work
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                </div>
            </div>
        </div>
    </section>

    @include('marketing.partials.cta')
@endsection
