@extends('layouts.marketing')

@section('title', 'Plans & pricing')
@section('description', 'Compare Chama Core plans for small chamas, growing organisations and multi-branch networks. Transparent monthly pricing and clear limits.')

@section('content')
    <section class="relative overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-jade-700 pb-16 pt-16 text-center sm:pt-20">
        <div class="relative mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Simple, transparent pricing</p>
            <h1 class="mt-5 text-4xl font-extrabold tracking-tight text-white sm:text-5xl">A plan for the group you are today.</h1>
            <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-300">
                Every plan includes a secure group workspace, role-based access and the shared ledger. Choose the member limit and modules that fit your organisation.
            </p>
            <div class="mt-7 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-200">
                <x-icon name="shield" class="h-4 w-4 text-jade-300" />
                Prices in KES · billed monthly · annual billing available on request
            </div>
        </div>
    </section>

    <section class="bg-slate-50 pb-20 sm:pb-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-6 lg:grid-cols-3">
                @foreach ($plans as $plan)
                    <div class="relative flex flex-col rounded-3xl border p-7 shadow-card sm:p-8 {{ $plan['featured'] ? 'border-jade-400 bg-ink-900 text-white ring-2 ring-jade-400/30' : 'border-slate-200 bg-white text-ink-900' }}">
                        @if ($plan['featured'])
                            <span class="absolute -top-3 left-8 rounded-full bg-jade-500 px-3 py-1 text-[11px] font-extrabold uppercase tracking-wide text-ink-950 shadow">Most popular</span>
                        @endif

                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-xl font-extrabold">{{ $plan['name'] }}</h2>
                                <p class="mt-1 text-sm {{ $plan['featured'] ? 'text-slate-300' : 'text-slate-500' }}">
                                    {{ $plan['max_members'] >= 1000 ? 'For established organisations' : ($plan['max_members'] >= 200 ? 'For growing groups' : 'For smaller groups') }}
                                </p>
                            </div>
                            @if ($plan['featured'])
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-jade-400/15 text-jade-300">
                                    <x-icon name="trending-up" class="h-5 w-5" />
                                </span>
                            @else
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white text-ocean-700">
                                    <x-icon name="building" class="h-5 w-5" />
                                </span>
                            @endif
                        </div>

                        <div class="mt-7">
                            <p class="text-3xl font-extrabold tracking-tight sm:text-4xl">{{ \App\Support\Money::format($plan['price']) }}</p>
                            <p class="mt-1 text-sm {{ $plan['featured'] ? 'text-slate-300' : 'text-slate-500' }}">per {{ $plan['interval'] }}</p>
                        </div>

                        <div class="mt-6 grid grid-cols-2 gap-2">
                            <div class="rounded-xl px-3 py-3 {{ $plan['featured'] ? 'bg-white/5' : 'bg-slate-50' }}">
                                <p class="text-[11px] uppercase tracking-wide {{ $plan['featured'] ? 'text-slate-400' : 'text-slate-500' }}">Members</p>
                                <p class="mt-0.5 text-sm font-bold">Up to {{ number_format($plan['max_members']) }}</p>
                            </div>
                            <div class="rounded-xl px-3 py-3 {{ $plan['featured'] ? 'bg-white/5' : 'bg-slate-50' }}">
                                <p class="text-[11px] uppercase tracking-wide {{ $plan['featured'] ? 'text-slate-400' : 'text-slate-500' }}">Workspaces</p>
                                <p class="mt-0.5 text-sm font-bold">{{ $plan['max_groups'] }} {{ $plan['max_groups'] === 1 ? 'group' : 'groups' }}</p>
                            </div>
                        </div>

                        <ul class="mt-7 flex-1 space-y-3">
                            <li class="flex items-start gap-2 text-sm {{ $plan['featured'] ? 'text-slate-200' : 'text-slate-700' }}">
                                <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-jade-500" />
                                {{ $plan['trial_days'] > 0 ? $plan['trial_days'].'-day setup period' : 'Immediate setup' }}
                            </li>
                            @foreach ($plan['modules'] as $module)
                                <li class="flex items-start gap-2 text-sm {{ $plan['featured'] ? 'text-slate-200' : 'text-slate-700' }}">
                                    <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-jade-500" />
                                    {{ $module }}
                                </li>
                            @endforeach
                        </ul>

                        <a href="{{ route('marketing.contact', ['plan' => $plan['slug']]) }}"
                           class="mt-8 inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold transition {{ $plan['featured'] ? 'bg-jade-500 text-ink-950 hover:bg-jade-400' : 'bg-ocean-600 text-white hover:bg-ocean-700' }}">
                            Choose {{ $plan['name'] }}
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </a>
                    </div>
                @endforeach
            </div>

            <p class="mt-8 text-center text-sm text-slate-500">
                Not sure which plan fits? <a href="{{ route('marketing.contact') }}" class="font-bold text-ocean-700 hover:underline">Tell us about your group</a> and we will recommend one.
            </p>
        </div>
    </section>

    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-700">Included on every plan</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">The foundations never change.</h2>
                <p class="mt-4 text-base leading-7 text-slate-600">Plans change limits and modules. They do not change the way your records are protected.</p>
            </div>

            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['icon' => 'shield', 'title' => 'Group isolation', 'body' => 'Members and officials only access the group context they belong to.'],
                    ['icon' => 'book', 'title' => 'Double-entry ledger', 'body' => 'Money movements post with balanced debits and credits.'],
                    ['icon' => 'users', 'title' => 'Role permissions', 'body' => 'Give each committee member only the tools they need.'],
                    ['icon' => 'receipt', 'title' => 'Audit trail', 'body' => 'Trace changes, approvals and payment references when questions arise.'],
                ] as $foundation)
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-6">
                        <x-icon :name="$foundation['icon']" class="h-6 w-6 text-ocean-700" />
                        <h3 class="mt-4 text-base font-bold text-ink-900">{{ $foundation['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $foundation['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-700">Good to know</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900">Pricing questions, answered.</h2>
            </div>

            <div class="mt-10 space-y-4">
                @foreach ([
                    ['Can we change plans later?', 'Yes. Your plan can be changed as your membership grows or your group needs more modules. Ask us about the best time to switch.'],
                    ['Do members pay separately?', 'No. Members join the workspace created by their group. There is no separate member licence to buy.'],
                    ['Can we pay annually?', 'Yes. Annual billing can be arranged. Request access and tell us which plan you prefer.'],
                    ['What happens during setup?', 'We confirm your roles, products, charges, reminders and payment details before members are invited.'],
                ] as $faq)
                    <details class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-bold text-ink-900">
                            {{ $faq[0] }}
                            <x-icon name="chevron-down" class="h-5 w-5 shrink-0 text-slate-400 transition group-open:rotate-180" />
                        </summary>
                        <p class="mt-3 text-sm leading-6 text-slate-600">{{ $faq[1] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    @include('marketing.partials.cta')
@endsection
