@extends('layouts.marketing')

@section('title', 'Group finance, without the guesswork')
@section('description', 'Chama Core brings savings, loans, merry-go-round, bills, payments, reports and governance into one secure workspace for groups and organisations.')

@section('content')
    {{-- Hero --}}
    <section class="relative grid-noise overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-ocean-700 pb-24 pt-16 sm:pt-20 lg:pb-32">

        <div class="relative mx-auto grid max-w-7xl items-center gap-14 px-4 sm:px-6 lg:grid-cols-[1.02fr_0.98fr] lg:gap-10 lg:px-8">
            <div class="text-white">
                <div class="inline-flex items-center gap-2 rounded-full border border-jade-300/30 bg-jade-400/10 px-3 py-1.5 text-xs font-semibold text-jade-200">
                    <span class="h-2 w-2 rounded-full bg-jade-400"></span>
                    Built for chamas, SACCOs, welfare groups and organisations
                </div>

                <h1 class="mt-7 text-4xl font-extrabold leading-[1.08] tracking-tight sm:text-5xl lg:text-6xl">
                    Run every group fund with
                    <span class="bg-gradient-to-r from-ocean-300 via-jade-300 to-sun-400 bg-clip-text text-transparent">clarity and confidence.</span>
                </h1>

                <p class="mt-6 max-w-xl text-base leading-7 text-slate-300 sm:text-lg">
                    Chama Core replaces scattered notebooks, spreadsheets and payment messages with one secure system for savings, loans, merry-go-round, bills, wallets and reports.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="{{ route('marketing.contact') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-jade-500 px-6 py-3.5 text-sm font-bold text-ink-950 shadow-lg shadow-jade-900/20 transition hover:bg-jade-400 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-ink-900">
                        Request access
                        <x-icon name="arrow-right" class="h-4 w-4" />
                    </a>
                    <a href="{{ route('marketing.features') }}" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/20 px-6 py-3.5 text-sm font-bold text-white transition hover:bg-white/10">
                        Explore features
                    </a>
                </div>

                <dl class="mt-10 grid max-w-xl grid-cols-2 gap-x-6 gap-y-5 border-t border-white/10 pt-7 sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-slate-400">One ledger</dt>
                        <dd class="mt-1 text-sm font-semibold text-white">Every shilling accounted for</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-slate-400">Role based</dt>
                        <dd class="mt-1 text-sm font-semibold text-white">Members see their own records</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wider text-slate-400">Payment ready</dt>
                        <dd class="mt-1 text-sm font-semibold text-white">M-Pesa, wallet and bank flows</dd>
                    </div>
                </dl>
            </div>

            {{-- Product preview --}}
            <div class="relative">
                <div class="relative overflow-hidden rounded-2xl border border-white/15 bg-ink-950/80 shadow-lift backdrop-blur">
                    <div class="flex items-center justify-between border-b border-white/10 px-5 py-3.5">
                        <div class="flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full bg-coral-500"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-sun-400"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-jade-400"></span>
                        </div>
                        <p class="text-xs font-medium text-slate-400">Group overview · illustrative</p>
                        <span class="rounded-full bg-jade-400/10 px-2 py-1 text-[10px] font-bold text-jade-300">LIVE</span>
                    </div>

                    <div class="p-5 sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs text-slate-400">Umoja Women's Group</p>
                                <p class="mt-1 text-lg font-bold text-white">Financial position</p>
                            </div>
                            <span class="rounded-lg bg-white/5 px-2.5 py-1 text-xs text-slate-300">This month</span>
                        </div>

                        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach ([
                                ['Cash position', 'KES 842,400', '+12.4%', 'text-jade-300'],
                                ['Member savings', 'KES 1,264,000', '+8.1%', 'text-jade-300'],
                                ['Loan balance', 'KES 318,500', '-3.2%', 'text-sun-400'],
                            ] as $stat)
                                <div class="rounded-xl border border-white/10 bg-white/[0.04] p-3.5">
                                    <p class="truncate text-[11px] text-slate-400">{{ $stat[0] }}</p>
                                    <p class="mt-1.5 text-sm font-bold text-white sm:text-base">{{ $stat[1] }}</p>
                                    <p class="mt-1 text-[11px] font-semibold {{ $stat[3] }}">{{ $stat[2] }}</p>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 rounded-xl border border-white/10 bg-white/[0.04] p-4">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-white">Collections trend</p>
                                <p class="text-xs text-slate-400">Last 8 weeks</p>
                            </div>
                            <div class="mt-5 flex h-28 items-end gap-2">
                                @foreach ([38, 52, 44, 68, 58, 78, 72, 92] as $height)
                                    <div class="flex-1 rounded-t-md bg-gradient-to-t from-ocean-600/40 to-jade-400" style="height: {{ $height }}%"></div>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-xl border border-white/10 bg-white/[0.04] p-4">
                                <div class="flex items-center gap-2 text-xs font-semibold text-jade-300">
                                    <x-icon name="check" class="h-4 w-4" /> Reconciled today
                                </div>
                                <p class="mt-2 text-sm text-slate-300">18 MPesa payments matched to member accounts.</p>
                            </div>
                            <div class="rounded-xl border border-white/10 bg-white/[0.04] p-4">
                                <div class="flex items-center gap-2 text-xs font-semibold text-sun-400">
                                    <x-icon name="bell" class="h-4 w-4" /> Reminder due
                                </div>
                                <p class="mt-2 text-sm text-slate-300">12 MGR contributions due in 3 days.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="absolute -bottom-6 -left-4 hidden items-center gap-3 rounded-xl border border-white/10 bg-ink-900/95 px-4 py-3 shadow-lift backdrop-blur sm:flex">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-jade-500/15 text-jade-300">
                        <x-icon name="shield" class="h-5 w-5" />
                    </span>
                    <div>
                        <p class="text-xs font-bold text-white">Tenant isolated</p>
                        <p class="text-[11px] text-slate-400">Each group sees only its own data</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Problems --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-coral-600">The real problem</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">
                    Group funds do not fail because members do not care. They fail because the records are hard to trust.
                </h2>
                <p class="mt-4 text-base leading-7 text-slate-600">
                    When contributions, loans and payouts live in different books, even a careful committee spends its time reconciling instead of serving members.
                </p>
            </div>

            <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['icon' => 'book', 'title' => 'Scattered records', 'body' => 'Notebooks, spreadsheets and phone messages never agree on the same balance.'],
                    ['icon' => 'clock', 'title' => 'Late contributions', 'body' => 'Members forget due dates and officials chase payments without a clear list.'],
                    ['icon' => 'shield', 'title' => 'Unclear approvals', 'body' => 'Loan and payout decisions are hard to audit when authority lives in inboxes.'],
                    ['icon' => 'chart', 'title' => 'Slow reports', 'body' => 'Every meeting needs a fresh summary assembled by hand from several sources.'],
                ] as $problem)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-card transition hover:-translate-y-1 hover:border-coral-200 hover:shadow-lift">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-coral-50 text-coral-600">
                            <x-icon :name="$problem['icon']" class="h-5 w-5" />
                        </span>
                        <h3 class="mt-5 text-base font-bold text-ink-900">{{ $problem['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $problem['body'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-8 flex items-center gap-3 rounded-2xl border border-ocean-200 bg-ocean-50 px-5 py-4 text-sm text-ocean-700">
                <x-icon name="arrow-right" class="h-5 w-5 shrink-0 rotate-90" />
                <p><span class="font-bold">The answer is not more spreadsheets.</span> It is one ledger, one member view and one set of rules that everyone can see.</p>
            </div>
        </div>
    </section>

    {{-- Solution --}}
    <section class="bg-gradient-to-b from-ocean-50 via-white to-jade-50 py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-700">One connected system</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">
                        From contribution to decision, everything stays connected.
                    </h2>
                </div>
                <a href="{{ route('marketing.features') }}" class="inline-flex items-center gap-2 text-sm font-bold text-ocean-700 hover:text-ocean-800">
                    See every capability
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>

            <div class="mt-12 grid gap-6 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'wallet', 'tone' => 'bg-white text-ocean-700', 'title' => 'Money in, tracked', 'body' => 'Savings, MGR contributions, bills and wallet deposits post to member accounts and the group ledger in one step.', 'points' => ['M-Pesa STK push', 'Wallet and teller entries', 'Automatic charge rules']],
                    ['icon' => 'scale', 'tone' => 'bg-jade-100 text-jade-700', 'title' => 'Decisions with guardrails', 'body' => 'Define who can approve loans, payouts and adjustments. Every action carries an audit trail.', 'points' => ['Role-based permissions', 'Approval workflows', 'Branch inheritance']],
                    ['icon' => 'chart', 'tone' => 'bg-sun-100 text-slate-700', 'title' => 'Answers in seconds', 'body' => 'Committees open a dashboard that already knows the cash position, arrears, reminders and member statements.', 'points' => ['Live dashboard', 'Financial statements', 'Date-based reminders']],
                ] as $solution)
                    <div class="rounded-2xl border border-slate-200 bg-white p-7 shadow-card">
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl {{ $solution['tone'] }}">
                            <x-icon :name="$solution['icon']" class="h-6 w-6" />
                        </span>
                        <h3 class="mt-5 text-lg font-bold text-ink-900">{{ $solution['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $solution['body'] }}</p>
                        <ul class="mt-5 space-y-2.5 border-t border-slate-100 pt-5">
                            @foreach ($solution['points'] as $point)
                                <li class="flex items-center gap-2 text-sm text-slate-700">
                                    <x-icon name="check" class="h-4 w-4 shrink-0 text-jade-600" />
                                    {{ $point }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Feature highlights --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'users', 'title' => 'Member onboarding', 'body' => 'Invite members, collect applications and verify documents without chasing paper forms.'],
                    ['icon' => 'banknotes', 'title' => 'Savings & share accounts', 'body' => 'Products, deposits, withdrawals and statements for every member account.'],
                    ['icon' => 'calendar', 'title' => 'Merry-go-round', 'body' => 'Cycles, contribution order, opt-in payments and payout tracking in one view.'],
                    ['icon' => 'credit-card', 'title' => 'Loans & guarantees', 'body' => 'Quote repayments, record guarantees, approve, disburse and post repayments.'],
                    ['icon' => 'receipt', 'title' => 'Bills & charges', 'body' => 'Issue bills, configure payment charges and let members pay from their portal.'],
                    ['icon' => 'building', 'title' => 'Branches & oversight', 'body' => 'Run a head office and branches with inherited policies and platform-level reporting.'],
                ] as $feature)
                    <a href="{{ route('marketing.features') }}" class="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-ocean-300 hover:shadow-lift">
                        <div class="flex items-start justify-between">
                            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white text-ocean-700 transition group-hover:bg-white">
                                <x-icon :name="$feature['icon']" class="h-5 w-5" />
                            </span>
                            <x-icon name="arrow-right" class="h-4 w-4 text-slate-300 transition group-hover:translate-x-1 group-hover:text-ocean-600" />
                        </div>
                        <h3 class="mt-5 text-base font-bold text-ink-900">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $feature['body'] }}</p>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Who it is for --}}
    <section class="bg-ink-900 py-20 text-white sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="max-w-2xl">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Built for real groups</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Different organisations. The same need for trusted records.</h2>
            </div>

            <div class="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['icon' => 'users', 'title' => 'Chamas & investment clubs', 'body' => 'Keep contributions, MGR, loans and social funds in one member-friendly place.'],
                    ['icon' => 'building', 'title' => 'SACCOs & cooperatives', 'body' => 'Manage branches, products, approvals and member statements with clear separation.'],
                    ['icon' => 'heart', 'title' => 'Welfare & community groups', 'body' => 'Track welfare contributions, claims and disbursements transparently.'],
                    ['icon' => 'shield', 'title' => 'Employer & umbrella bodies', 'body' => 'Oversee multiple groups while each unit keeps its own boundaries and reports.'],
                ] as $audience)
                    <div class="rounded-2xl border border-white/10 bg-white/[0.04] p-6">
                        <x-icon :name="$audience['icon']" class="h-6 w-6 text-jade-300" />
                        <h3 class="mt-5 text-base font-bold">{{ $audience['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-300">{{ $audience['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- How to start --}}
    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-700">Simple start</p>
                <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900 sm:text-4xl">From your current process to a clean workspace in three steps.</h2>
            </div>

            <div class="mt-12 grid gap-6 lg:grid-cols-3">
                @foreach ([
                    ['step' => '01', 'title' => 'Tell us about your group', 'body' => 'Share your group type, member count and the workflows you need today.'],
                    ['step' => '02', 'title' => 'We configure the workspace', 'body' => 'Roles, products, charges, reminders and branches are set up to match how you operate.'],
                    ['step' => '03', 'title' => 'Invite your members', 'body' => 'Members join through invitations and see only their own records in the portal.'],
                ] as $step)
                    <div class="relative rounded-2xl border border-slate-200 bg-white p-7 shadow-card">
                        <span class="text-4xl font-extrabold text-ocean-100">{{ $step['step'] }}</span>
                        <h3 class="mt-2 text-lg font-bold text-ink-900">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $step['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Pricing teaser --}}
    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid items-center gap-10 rounded-3xl bg-gradient-to-br from-ocean-600 to-jade-600 p-8 text-white shadow-lift sm:p-12 lg:grid-cols-[1fr_auto]">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-100">Plans that grow with you</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl">Start with the module your group needs. Add more when you are ready.</h2>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-ocean-50">Clear monthly plans for small groups, growing organisations and multi-branch networks.</p>
                </div>
                <a href="{{ route('marketing.pricing') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3.5 text-sm font-bold text-ocean-700 shadow-lg transition hover:bg-slate-50">
                    View plans
                    <x-icon name="arrow-right" class="h-4 w-4" />
                </a>
            </div>
        </div>
    </section>

    @include('marketing.partials.cta')
@endsection
