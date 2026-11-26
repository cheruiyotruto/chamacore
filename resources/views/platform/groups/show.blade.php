@extends('layouts.platform')

@section('title', $group->name)

@section('content')
    @php
        $latestSubscription = $group->subscriptions->first();
        $activeSubscription = $group->subscriptions->first(fn ($subscription) => $subscription->isActive());
        $members = $group->members;
        $commissionRate = $stats['members'] > 0 ? (int) round(($stats['active_members'] / $stats['members']) * 100) : 0;
        $outstandingInvoices = $invoices->whereIn('status', ['unpaid', 'partial', 'overdue'])->sum('amount');
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $group->name }}</h1>
                <x-badge :status="$group->status" />
                <x-badge :status="$group->subscription_status" />
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $group->slug }} · created {{ $group->created_at?->format('d M Y') ?? '—' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('platform.groups.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="arrow-left" class="h-4 w-4" /> All groups
            </a>

            @if ($group->allowsFullPlatformControl() && $group->status === 'active')
                <form method="POST" action="{{ route('platform.groups.enter', $group) }}">
                    @csrf
                    <button class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="arrow-right" class="h-4 w-4" /> Open tenant
                    </button>
                </form>
            @endif

            @if ($group->status !== 'active')
                <form method="POST" action="{{ route('platform.groups.approve', $group) }}" onsubmit="return confirm('Approve and activate {{ $group->name }}?');">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        <x-icon name="check" class="h-4 w-4" /> {{ $group->status === 'suspended' ? 'Reactivate' : 'Approve' }}
                    </button>
                </form>
            @endif

            @if ($group->status !== 'suspended')
                <details class="relative">
                    <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg border border-rose-300 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                        <x-icon name="x" class="h-4 w-4" /> Suspend
                    </summary>
                    <form method="POST" action="{{ route('platform.groups.suspend', $group) }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg" onsubmit="return confirm('Suspend {{ $group->name }}? Members will lose access.');">
                        @csrf
                        <p class="text-sm font-semibold text-slate-900">Suspend this group</p>
                        <x-textarea name="reason" label="Reason (optional)" rows="3" placeholder="Recorded in the audit trail" />
                        <button type="submit" class="w-full rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700">Suspend group</button>
                    </form>
                </details>
            @endif
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Members" :value="number_format($stats['members'])" :hint="$stats['active_members'].' active · '.$commissionRate.'%'" />
        <x-stat label="Savings" :value="\App\Support\Money::format($stats['savings_balance'])" :hint="$stats['savings_accounts'].' savings account(s)'" tone="info" />
        <x-stat label="Loan book" :value="\App\Support\Money::format($stats['loan_outstanding'])" :hint="$stats['loans'].' loan(s) disbursed'" tone="warning" />
        <x-stat label="Outstanding invoices" :value="\App\Support\Money::format($outstandingInvoices)" :hint="$invoices->count().' recent invoice(s)'" :tone="$outstandingInvoices > 0 ? 'danger' : 'brand'" />
    </div>

    <div class="mt-6 grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Platform management"
                subtitle="Choose whether the platform only oversees this organisation or can also operate inside its tenant.">
                <form method="POST" action="{{ route('platform.groups.management-mode.update', $group) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @method('PUT')
                    <div class="min-w-64 flex-1">
                        <x-select name="platform_management_mode" label="Mode" :options="$managementModes" :value="$group->platform_management_mode ?? \App\Models\Group::PLATFORM_MODE_SELF" />
                    </div>
                    <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save mode</button>
                </form>
                <div class="mt-4 grid gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-3">
                    <div>
                        <p class="text-slate-500">Effective mode</p>
                        <p class="font-medium text-slate-800">{{ $group->platformManagementLabel() }}</p>
                    </div>
                    <div>
                        <p class="text-slate-500">Set by</p>
                        <p class="font-medium text-slate-800">{{ $group->platformManager?->name ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-slate-500">Income reported</p>
                        <p class="font-medium text-slate-800">@money($stats['managed_income'])</p>
                    </div>
                </div>
                @if ($group->isBranch())
                    <p class="mt-3 text-xs text-slate-500">Branches inherit the head office mode unless they are explicitly set to self-managed.</p>
                @endif
            </x-card>

            <x-card title="Public member sign-up"
                    subtitle="Opt this organisation into the public join directory. Applications always remain pending until approved.">
                <form method="POST" action="{{ route('platform.groups.public-join.update', $group) }}" class="space-y-4">
                    @csrf
                    @method('PUT')

                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <input type="checkbox" name="public_join_enabled" value="1" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500" @checked($group->public_join_enabled)>
                        <span>
                            <span class="block text-sm font-semibold text-slate-900">List this group on the public join page</span>
                            <span class="mt-0.5 block text-xs text-slate-500">Only active, platform-managed head offices can be listed. Branches stay private.</span>
                        </span>
                    </label>

                    <div>
                        <label for="public_join_summary" class="block text-sm font-medium text-slate-700">Public description</label>
                        <textarea id="public_join_summary" name="public_join_summary" rows="3" maxlength="1000"
                                  class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500"
                                  placeholder="Tell applicants what this group is about and what happens after they apply.">{{ old('public_join_summary', $group->public_join_summary) }}</textarea>
                        <p class="mt-1 text-xs text-slate-500">Shown on the public directory and application page.</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save sign-up settings</button>
                        @if ($group->public_join_enabled)
                            <a href="{{ route('public.join.apply', $group) }}" target="_blank" class="text-sm font-semibold text-brand-700 hover:text-brand-800">View public page ↗</a>
                        @endif
                    </div>
                </form>
            </x-card>

            @if ($group->allowsPlatformOversight())
                <x-card title="Payment charges for this organisation"
                    subtitle="These values override platform defaults and apply to this tenant.">
                    <form method="POST" action="{{ route('platform.groups.payment-charges.update', $group) }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        @include('components.payment-charge-rules', [
                            'rules' => $chargeRules,
                            'categories' => $chargeCategories,
                            'modes' => $chargeModes,
                            'borneBy' => $borneBy,
                            'incomeAccounts' => $incomeAccounts,
                            'canManage' => true,
                            'money' => $money,
                            'inherited' => false,
                        ])
                    </form>
                </x-card>
            @endif

            <x-card title="Group profile">
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Type</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">
                            {{ $group->isBranch() ? 'Branch' : 'Organisation' }}
                            @if ($group->parent)
                                <span class="block text-xs text-slate-400">Under {{ $group->parent->name }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Registration number</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $group->registration_no ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Currency / timezone</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $group->currency }} · {{ $group->timezone }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Email</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $group->email ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $group->phone ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">County</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $group->county ?: '—' }}{{ $group->sub_county ? ' · '.$group->sub_county : '' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Created by</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">
                            {{ $group->creator?->name ?? 'System' }}
                            <span class="block text-xs text-slate-400">{{ $group->creator?->email }}</span>
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-slate-500">Address</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $group->address ?: '—' }}</dd>
                    </div>
                    @if ($group->description)
                        <div class="sm:col-span-2">
                            <dt class="text-slate-500">Description</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-slate-700">{{ $group->description }}</dd>
                        </div>
                    @endif
                </dl>
            </x-card>

            @if ($group->branches->isNotEmpty())
                <x-card title="Branches" :subtitle="$group->branches->count().' direct branch(es)'" :padding="false">
                    <div class="divide-y divide-slate-100">
                        @foreach ($group->branches as $branch)
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <a href="{{ route('platform.groups.show', $branch) }}" class="text-sm font-medium text-slate-900 hover:text-brand-700">{{ $branch->name }}</a>
                                    <p class="text-xs text-slate-400">{{ $branch->branch_code }} · {{ $branch->members_count }} member(s)</p>
                                </div>
                                <x-badge :status="$branch->status" />
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            <x-card title="Members" :subtitle="$members->count().' shown (latest 50)'" :padding="false">
                @if ($members->isEmpty())
                    <x-empty title="No members" message="This group has not added any members yet." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Member</th>
                                    <th class="px-4 py-3">Role</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Joined</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($members as $member)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5">
                                            <p class="font-medium text-slate-800">{{ $member->full_name }}</p>
                                            <p class="text-xs text-slate-400">{{ $member->member_no }} · {{ $member->email ?: $member->phone ?: 'no contact' }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $member->role?->name ?? '—' }}</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$member->status" /></td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $member->created_at?->format('d M Y') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>

            <x-card title="Invoices" :subtitle="$invoices->count().' most recent'" :padding="false">
                @if ($invoices->isEmpty())
                    <x-empty title="No invoices" message="Subscription invoices appear here once billing starts." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Invoice</th>
                                    <th class="px-4 py-3">Issued</th>
                                    <th class="px-4 py-3">Due</th>
                                    <th class="px-4 py-3 text-right">Amount</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Paid</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($invoices as $invoice)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5 font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_no }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $invoice->issue_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($invoice->amount)</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$invoice->status" /></td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $invoice->paid_at?->format('d M Y') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Subscription">
                @if (! $latestSubscription)
                    <x-empty title="No subscription" message="This group has never subscribed to a plan." />
                @else
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Plan</dt>
                            <dd class="font-medium text-slate-800">{{ $latestSubscription->plan?->name ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Price</dt>
                            <dd class="font-medium text-slate-800">{{ $latestSubscription->plan?->priceLabel() ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Status</dt>
                            <dd><x-badge :status="$latestSubscription->status" /></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Started</dt>
                            <dd class="font-medium text-slate-800">{{ $latestSubscription->starts_at?->format('d M Y') ?? '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Ends</dt>
                            <dd class="font-medium text-slate-800">{{ $latestSubscription->ends_at?->format('d M Y') ?? 'Open-ended' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Auto-renew</dt>
                            <dd class="font-medium text-slate-800">{{ $latestSubscription->auto_renew ? 'On' : 'Off' }}</dd>
                        </div>
                    </dl>

                    @if ($latestSubscription->plan?->modules)
                        <div class="mt-4 flex flex-wrap gap-1.5 border-t border-slate-100 pt-3">
                            @foreach ($latestSubscription->plan->modules as $module)
                                <x-badge status="info" :label="ucfirst($module)" />
                            @endforeach
                        </div>
                    @endif

                    @if ($activeSubscription && $activeSubscription->id !== $latestSubscription->id)
                        <p class="mt-3 text-xs text-slate-500">An older active subscription still exists for this group.</p>
                    @endif
                @endif

                <form method="POST" action="{{ route('platform.groups.plan.update', $group) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-4">
                    @csrf
                    @method('PUT')
                    <label for="plan_id" class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Assign plan</label>
                    <select id="plan_id" name="plan_id" class="w-full rounded-lg border-slate-300 text-sm" required>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected($latestSubscription?->plan_id === $plan->id)>
                                {{ $plan->name }} — {{ $plan->priceLabel() }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        Assign plan
                    </button>
                    <p class="text-xs text-slate-500">Free plans never raise an invoice; transaction charges are collected by the platform instead.</p>
                </form>
            </x-card>

            <x-card title="Subscription history" :subtitle="$group->subscriptions->count().' record(s)'" :padding="false">
                @if ($group->subscriptions->isEmpty())
                    <x-empty title="No history" message="Subscription changes are recorded here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($group->subscriptions as $subscription)
                            <div class="px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-slate-800">{{ $subscription->plan?->name ?? 'Archived plan' }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ $subscription->starts_at?->format('d M Y') ?? '—' }} → {{ $subscription->ends_at?->format('d M Y') ?? 'open' }}
                                        </p>
                                    </div>
                                    <x-badge :status="$subscription->status" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Administration">
                <div class="space-y-2">
                    @if ($group->status !== 'active' && $group->status === 'suspended')
                        <form method="POST" action="{{ route('platform.groups.reactivate', $group) }}">
                            @csrf
                            <button type="submit" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Reactivate group</button>
                        </form>
                    @endif
                    <a href="{{ route('platform.groups.index', ['search' => $group->email ?: $group->name]) }}" class="block w-full rounded-lg border border-slate-300 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        Find related groups
                    </a>
                </div>
                <p class="mt-3 text-xs text-slate-500">All platform actions are written to the audit log with the acting administrator.</p>
            </x-card>
        </div>
    </div>
@endsection
