@extends('layouts.app')

@section('title', 'Payments')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
        $statusOptions = collect($statuses)->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all();
        $purposeOptions = collect($purposes)->mapWithKeys(fn ($purpose) => [$purpose => ucfirst(str_replace('_', ' ', $purpose))])->all();
        $succeeded = $intents->getCollection()->where('status', 'succeeded')->sum('amount');
        $pending = $intents->getCollection()->whereIn('status', ['pending', 'processing'])->sum('amount');
    @endphp

    <x-page-header title="Payments" :subtitle="$intents->total().' payment request(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($can('payments.initiate'))
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> Request payment
                </summary>
                <form method="POST" action="{{ route('payments.store') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Initiate a collection</p>
                    <x-select name="member_id" label="Member" :options="$memberOptions" placeholder="Select a member" required />
                    <x-select name="purpose" label="Purpose" :options="$purposeOptions" required />
                    <x-input name="amount" type="number" step="0.01" min="1" label="Amount" required />
                    <x-input name="phone" label="Phone" placeholder="2547XXXXXXXX" required />
                    <x-input name="savings_account_id" type="number" label="Savings account ID" help="Required for savings deposits" />
                    <x-input name="loan_id" type="number" label="Loan ID" help="Required for loan repayments" />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Send payment request</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Payments on page" :value="number_format($intents->count())" hint="Latest payment requests" />
        <x-stat label="Succeeded" :value="\App\Support\Money::format((int) $succeeded)" hint="Confirmed on this page" tone="info" />
        <x-stat label="Awaiting confirmation" :value="\App\Support\Money::format((int) $pending)" hint="Pending or processing" tone="warning" />
        <x-stat label="Total requests" :value="number_format($intents->total())" hint="Across all pages" tone="neutral" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('payments.index') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-select name="status" placeholder="All statuses" :options="$statusOptions" :value="request('status')" />
            <x-select name="purpose" placeholder="All purposes" :options="$purposeOptions" :value="request('purpose')" />
            <div class="flex items-end gap-2 sm:col-span-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Filter
                </button>
                <a href="{{ route('payments.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if ($intents->isEmpty())
            <x-empty title="No payment requests" message="Initiate a payment request or adjust the filters." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Purpose</th>
                            <th class="px-4 py-3">Phone</th>
                            <th class="px-4 py-3 text-right">Amount</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Created</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($intents as $intent)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('payments.show', $intent) }}" class="font-mono text-xs font-medium text-brand-700 hover:text-brand-800">{{ $intent->uuid }}</a>
                                    @if ($intent->provider_reference)
                                        <p class="text-xs text-slate-400">{{ $intent->provider_reference }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('members.show', $intent->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $intent->member?->full_name ?? '—' }}</a>
                                    <p class="text-xs text-slate-400">{{ $intent->member?->member_no }}</p>
                                </td>
                                <td class="px-4 py-3"><x-badge :status="$intent->status" :label="ucfirst(str_replace('_', ' ', $intent->purpose))" /></td>
                                <td class="px-4 py-3 text-slate-600">{{ $intent->phone ?: '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($intent->amount)</td>
                                <td class="px-4 py-3"><x-badge :status="$intent->status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $intent->created_at?->format('d M Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('payments.show', $intent) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $intents->links() }}</div>
        @endif
    </x-card>
@endsection
