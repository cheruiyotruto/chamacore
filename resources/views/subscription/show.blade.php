@extends('layouts.app')

@section('title', 'Subscription')

@section('content')
    @php
        $plan = $subscription?->plan;
        $active = $subscription?->isActive() ?? false;
        $daysLeft = $subscription?->ends_at ? now()->diffInDays($subscription->ends_at, false) : null;
        $invoiceDue = $invoices->getCollection()->whereIn('status', ['unpaid', 'partial', 'overdue'])->sum('amount');
    @endphp

    <x-page-header title="Subscription" :subtitle="'Plan and billing for '.$group->name">
        <x-badge :status="$active ? 'active' : 'inactive'" :label="$active ? 'Active subscription' : 'No active subscription'" />
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Current plan" :value="$plan?->name ?? 'None'" :hint="$plan ? $plan->priceLabel() : 'Choose a plan below'" />
        <x-stat label="Status" :value="ucfirst($subscription?->status ?? $group->subscription_status ?? 'inactive')" :hint="$subscription?->trial_ends_at ? 'Trial ends '.$subscription->trial_ends_at->format('d M Y') : 'Billed '.($plan?->interval ?? 'monthly')" :tone="$active ? 'brand' : 'warning'" />
        <x-stat label="Renews" :value="$subscription?->ends_at?->format('d M Y') ?? '—'" :hint="$daysLeft !== null ? ($daysLeft >= 0 ? $daysLeft.' day(s) remaining' : 'Expired '.abs($daysLeft).' day(s) ago') : 'Auto-renew '.($subscription?->auto_renew ? 'on' : 'off')" tone="info" />
        <x-stat label="Outstanding invoices" :value="\App\Support\Money::format($invoiceDue)" hint="On this page" :tone="$invoiceDue > 0 ? 'danger' : 'neutral'" />
    </div>

    @if ($subscription)
        <x-card title="Current subscription">
            <div class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-slate-500">Plan</p>
                    <p class="font-semibold text-slate-900">{{ $plan?->name ?? '—' }}</p>
                    <p class="text-xs text-slate-400">{{ $plan?->priceLabel() }}</p>
                </div>
                <div>
                    <p class="text-slate-500">Started</p>
                    <p class="font-medium text-slate-800">{{ $subscription->starts_at?->format('d M Y') ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-slate-500">Ends</p>
                    <p class="font-medium text-slate-800">{{ $subscription->ends_at?->format('d M Y') ?? 'No end date' }}</p>
                </div>
                <div>
                    <p class="text-slate-500">Auto-renew</p>
                    <p class="font-medium text-slate-800">{{ $subscription->auto_renew ? 'Enabled' : 'Disabled' }}</p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
                @if ($plan?->modules)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($plan->modules as $module)
                            <x-badge status="info" :label="ucfirst($module)" />
                        @endforeach
                    </div>
                @endif

                @if ($canManage && $active)
                    <form method="POST" action="{{ route('subscription.cancel', $subscription) }}" class="ml-auto" onsubmit="return confirm('Cancel this subscription? Access continues until the end of the current period.');">
                        @csrf
                        <button type="submit" class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Cancel subscription</button>
                    </form>
                @endif
            </div>
        </x-card>
    @endif

    <div>
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Available plans</h2>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($plans as $availablePlan)
                @php $current = $plan?->id === $availablePlan->id; @endphp
                <div class="flex flex-col rounded-xl border {{ $current ? 'border-brand-400 ring-2 ring-brand-100' : 'border-slate-200' }} bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-slate-900">{{ $availablePlan->name }}</h3>
                            <p class="mt-1 text-sm text-slate-500">{{ $availablePlan->priceLabel() }}</p>
                        </div>
                        @if ($current)
                            <x-badge status="active" label="Current" />
                        @endif
                    </div>

                    <ul class="mt-4 space-y-2 text-sm text-slate-600">
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> {{ $availablePlan->max_members ? number_format($availablePlan->max_members).' members' : 'Unlimited members' }}</li>
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> {{ $availablePlan->trial_days }} trial day(s)</li>
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> {{ count($availablePlan->modules ?? []) }} module(s): {{ implode(', ', array_slice($availablePlan->modules ?? [], 0, 4)) }}{{ count($availablePlan->modules ?? []) > 4 ? '…' : '' }}</li>
                    </ul>

                    <div class="mt-auto pt-4">
                        @if ($current)
                            <span class="block rounded-lg bg-slate-100 px-4 py-2 text-center text-sm font-semibold text-slate-500">Current plan</span>
                        @elseif ($canManage)
                            <form method="POST" action="{{ route('subscription.subscribe', $availablePlan) }}" onsubmit="return confirm('Switch to {{ $availablePlan->name }}? A new invoice will be issued.');">
                                @csrf
                                <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Choose {{ $availablePlan->name }}</button>
                            </form>
                        @else
                            <span class="block rounded-lg border border-slate-200 px-4 py-2 text-center text-sm text-slate-400">Contact an administrator</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <x-card title="Invoices" :subtitle="$invoices->total().' invoice(s)'" :padding="false">
        @if ($invoices->isEmpty())
            <x-empty title="No invoices" message="Subscription invoices are issued when a plan is activated." class="m-4" />
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
                            <th class="px-4 py-3">Paid at</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($invoices as $invoice)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-mono text-xs font-medium text-slate-800">{{ $invoice->invoice_no }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $invoice->issue_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($invoice->amount)</td>
                                <td class="px-4 py-3"><x-badge :status="$invoice->status" /></td>
                                <td class="px-4 py-3 text-slate-600">{{ $invoice->paid_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($canManage && $invoice->status !== 'paid')
                                        <details class="relative">
                                            <summary class="cursor-pointer list-none text-sm font-semibold text-brand-700 hover:text-brand-800">Record payment</summary>
                                            <form method="POST" action="{{ route('subscription.invoices.pay', $invoice) }}" class="absolute right-0 z-20 mt-2 w-72 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                                                @csrf
                                                <p class="text-sm font-semibold text-slate-900">Settle {{ $invoice->invoice_no }}</p>
                                                <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" :value="$invoice->amount / 100" required />
                                                <x-select name="method" label="Method" :options="['mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cash' => 'Cash', 'card' => 'Card']" required />
                                                <x-input name="reference" label="Reference" placeholder="Transaction reference" required />
                                                <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Record payment</button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $invoices->links() }}</div>
        @endif
    </x-card>
@endsection
