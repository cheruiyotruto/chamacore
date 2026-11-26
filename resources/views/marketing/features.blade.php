@extends('layouts.marketing')

@section('title', 'Features built around how groups actually work')
@section('description', 'Explore member onboarding, savings, merry-go-round, loans, bills, payments, reminders, branches, reports and platform oversight in Chama Core.')

@section('content')
    <section class="relative grid-noise overflow-hidden bg-gradient-to-br from-ink-900 via-ink-800 to-ocean-700 pb-20 pt-16 sm:pt-20">
        <div class="relative mx-auto max-w-7xl px-4 text-center sm:px-6 lg:px-8">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-jade-300">Platform capabilities</p>
            <h1 class="mx-auto mt-5 max-w-4xl text-4xl font-extrabold leading-tight tracking-tight text-white sm:text-5xl">
                Everything your group needs to move from scattered records to one trusted system.
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-300">
                Each module shares the same ledger, permissions and member identity. That means fewer manual handoffs, cleaner reports and a clearer experience for every member.
            </p>

            <div class="mt-9 flex flex-wrap justify-center gap-2">
                @foreach ([
                    ['Members', '#members'],
                    ['Savings & MGR', '#savings'],
                    ['Loans', '#loans'],
                    ['Payments', '#payments'],
                    ['Reminders', '#reminders'],
                    ['Reports', '#reports'],
                ] as $jump)
                    <a href="{{ $jump[1] }}" class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-200 transition hover:border-jade-300/40 hover:text-white">{{ $jump[0] }}</a>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-white py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="space-y-20">
                @foreach ([
                    [
                        'id' => 'members',
                        'eyebrow' => 'Members & governance',
                        'title' => 'Onboard members once. Give everyone the right view.',
                        'body' => 'Invite members with a secure link, collect applications and documents, then assign roles that match your constitution. Members see their own records; officials see the tools they are authorised to use.',
                        'icon' => 'users',
                        'tone' => 'ocean',
                        'points' => [
                            ['Invitation links', 'No public self-registration; each member is invited by an authorised official.'],
                            ['Identity requirements', 'Require photo, video or documents based on group policy.'],
                            ['Roles & permissions', 'Fine-grained access for staff, tellers, treasurers and members.'],
                        ],
                        'panel' => [
                            'title' => 'Member application',
                            'rows' => [
                                ['Amina Mwangi', 'Application submitted', 'Review'],
                                ['Brian Otieno', 'Documents verified', 'Approve'],
                                ['Catherine Wanjiru', 'Awaiting next of kin', 'Remind'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'savings',
                        'eyebrow' => 'Savings & merry-go-round',
                        'title' => 'Every contribution has a home and a clear sequence.',
                        'body' => 'Create savings products, post deposits and withdrawals, and run merry-go-round cycles with defined order and payout dates. Members can opt in to MGR payments and pay from the portal.',
                        'icon' => 'banknotes',
                        'tone' => 'jade',
                        'points' => [
                            ['Savings products', 'Configure accounts, deposits, withdrawals and statements.'],
                            ['MGR cycles', 'Track rotation, contributions, opt-outs and payouts.'],
                            ['Portal payments', 'Wallet or M-Pesa STK pushes recorded against the right cycle.'],
                        ],
                        'panel' => [
                            'title' => 'MGR rotation',
                            'rows' => [
                                ['Cycle 08', 'Amina Mwangi', 'Payout due'],
                                ['Contribution 24', 'Brian Otieno', 'Paid'],
                                ['Contribution 25', 'Catherine Wanjiru', 'Due in 3 days'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'loans',
                        'eyebrow' => 'Lending & approvals',
                        'title' => 'Lend with a paper trail the committee can actually read.',
                        'body' => 'Quote repayment schedules, record guarantors, request employer finance confirmation, approve and disburse loans, then post repayments and penalties. Outstanding balances and arrears update automatically.',
                        'icon' => 'credit-card',
                        'tone' => 'ocean',
                        'points' => [
                            ['Loan products', 'Set rates, periods, fees and eligibility rules.'],
                            ['Guarantees', 'Members respond to guarantee requests in their portal.'],
                            ['Employer confirmation', 'Signed links let finance offices confirm or decline.'],
                        ],
                        'panel' => [
                            'title' => 'Loan approval queue',
                            'rows' => [
                                ['LN-0042', 'Daniel Kiprop', 'Awaiting committee'],
                                ['LN-0041', 'Esther Njeri', 'Guaranteed'],
                                ['LN-0040', 'Francis Omondi', 'Disbursed'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'payments',
                        'eyebrow' => 'Bills, payments & payouts',
                        'title' => 'Collect money and pay out with fewer reconciliation headaches.',
                        'body' => 'Issue bills, accept wallet and M-Pesa payments, configure platform and group charges, and send money through bank, PayBill, B2B or B2C rails. Each movement is posted to the ledger with an audit reference.',
                        'icon' => 'receipt',
                        'tone' => 'jade',
                        'points' => [
                            ['Configurable charges', 'Percent, fixed, combined or manual rules by payment type.'],
                            ['Member wallets', 'Top up, transfer and pay bills or MGR contributions.'],
                            ['Payout rails', 'Bank transfers and M-Pesa disbursements with approval controls.'],
                        ],
                        'panel' => [
                            'title' => 'Payment activity',
                            'rows' => [
                                ['Bill #118', 'M-Pesa STK', 'Confirmed'],
                                ['MGR contribution', 'Wallet', 'Confirmed'],
                                ['B2C payout', 'M-Pesa', 'Processing'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'reminders',
                        'eyebrow' => 'Reminders & documents',
                        'title' => 'Nudge members before dates become problems.',
                        'body' => 'Create reminder rules for contributions, payouts, loans, bills, investment maturity and custom dates. Choose automatic recipients or a selected audience, and set offsets before or after the event.',
                        'icon' => 'bell',
                        'tone' => 'sun',
                        'points' => [
                            ['Flexible timing', 'Offsets such as -3, 0 or +2 days around a due date.'],
                            ['Right audience', 'Automatic, all members or a selected group.'],
                            ['Scheduled runs', 'Send manually or through the reminder command.'],
                        ],
                        'panel' => [
                            'title' => 'Reminder rules',
                            'rows' => [
                                ['MGR contribution', '3 days before', 'Active'],
                                ['Bill due date', 'On due date', 'Active'],
                                ['Loan repayment', '2 days before', 'Active'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'reports',
                        'eyebrow' => 'Reporting & oversight',
                        'title' => 'Walk into every meeting with the numbers already ready.',
                        'body' => 'Use the dashboard, trial balance, income statement, balance sheet, member statements and exportable reports. Branches inherit the nearest configured settings while head office keeps a clear oversight view.',
                        'icon' => 'chart',
                        'tone' => 'ocean',
                        'points' => [
                            ['Double-entry ledger', 'Every posted transaction has a debit and a credit.'],
                            ['Branches', 'Head office, branch codes and inherited policies.'],
                            ['Platform modes', 'Self-managed, oversight or full control where authorised.'],
                        ],
                        'panel' => [
                            'title' => 'Report library',
                            'rows' => [
                                ['Trial balance', 'Posted ledger', 'Export'],
                                ['Member statement', 'Per member', 'Export'],
                                ['Platform income', 'Report only', 'Review'],
                            ],
                        ],
                    ],
                ] as $index => $feature)
                    <div id="{{ $feature['id'] }}" class="scroll-mt-24 grid items-center gap-10 lg:grid-cols-2 lg:gap-16 {{ $index % 2 === 1 ? 'lg:[&>*:first-child]:order-2' : '' }}">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.2em] text-ocean-700">{{ $feature['eyebrow'] }}</p>
                            <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900">{{ $feature['title'] }}</h2>
                            <p class="mt-4 text-base leading-7 text-slate-600">{{ $feature['body'] }}</p>

                            <div class="mt-7 space-y-4">
                                @foreach ($feature['points'] as $point)
                                    <div class="flex gap-3">
                                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-jade-100 text-jade-700">
                                            <x-icon name="check" class="h-3.5 w-3.5" />
                                        </span>
                                        <div>
                                            <p class="text-sm font-bold text-ink-900">{{ $point[0] }}</p>
                                            <p class="mt-0.5 text-sm text-slate-600">{{ $point[1] }}</p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-card sm:p-6">
                            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-{{ $feature['tone'] }}-100 text-{{ $feature['tone'] }}-700">
                                            <x-icon :name="$feature['icon']" class="h-4 w-4" />
                                        </span>
                                        <p class="text-sm font-bold text-ink-900">{{ $feature['panel']['title'] }}</p>
                                    </div>
                                    <span class="rounded-full bg-jade-50 px-2 py-1 text-[10px] font-bold text-jade-700">LIVE VIEW</span>
                                </div>
                                <div class="divide-y divide-slate-100">
                                    @foreach ($feature['panel']['rows'] as $row)
                                        <div class="flex items-center justify-between gap-3 px-4 py-3.5 text-sm">
                                            <span class="font-semibold text-ink-900">{{ $row[0] }}</span>
                                            <span class="hidden text-slate-500 sm:block">{{ $row[1] }}</span>
                                            <span class="rounded-full bg-ocean-50 px-2 py-1 text-[11px] font-semibold text-ocean-700">{{ $row[2] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="mt-4 flex items-center gap-2 rounded-xl border border-ocean-200 bg-ocean-50 px-4 py-3 text-xs text-ocean-700">
                                <x-icon name="shield" class="h-4 w-4 shrink-0" />
                                <p>Access is scoped by group and role. Members never see another member's records.</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="bg-slate-50 py-20 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid items-start gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-coral-600">Why it matters</p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-ink-900">Spreadsheets show numbers. Chama Core shows what happened and why.</h2>
                    <p class="mt-4 text-base leading-7 text-slate-600">When a member asks a question, your team can trace the answer from the portal payment back to the ledger entry, approval and receipt.</p>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-card">
                    <div class="grid grid-cols-2 border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wide">
                        <div class="px-5 py-3 text-slate-500">Manual process</div>
                        <div class="border-l border-slate-200 bg-jade-50 px-5 py-3 text-jade-700">With Chama Core</div>
                    </div>
                    @foreach ([
                        ['Balances typed by hand', 'Balances posted from each transaction'],
                        ['Payment messages checked separately', 'Payments reconciled against bills and members'],
                        ['Approvals lost in chat', 'Permissions and audit trail built in'],
                        ['Reports rebuilt every meeting', 'Reports available whenever you need them'],
                        ['Branches keep separate files', 'Branches inherit settings and report cleanly'],
                    ] as $comparison)
                        <div class="grid grid-cols-2 border-b border-slate-100 text-sm last:border-0">
                            <div class="flex items-start gap-2 px-5 py-4 text-slate-600">
                                <x-icon name="x" class="mt-0.5 h-4 w-4 shrink-0 text-coral-500" />
                                {{ $comparison[0] }}
                            </div>
                            <div class="flex items-start gap-2 border-l border-slate-200 bg-jade-50/40 px-5 py-4 font-medium text-ink-900">
                                <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-jade-600" />
                                {{ $comparison[1] }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    @include('marketing.partials.cta')
@endsection
