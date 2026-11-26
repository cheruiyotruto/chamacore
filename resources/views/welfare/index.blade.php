@extends('layouts.app')

@section('title', 'Welfare')

@section('content')
    @php
        $statusOptions = collect($statuses)->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all();
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
        $totalBalance = $funds->sum('balance');
        $pendingClaims = $claims->getCollection()->where('status', 'pending')->count();
    @endphp

    <x-page-header title="Welfare" :subtitle="$funds->count().' fund(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($canManage)
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> New fund
                </summary>
                <form method="POST" action="{{ route('welfare.funds.store') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Create a welfare fund</p>
                    <x-input name="name" label="Name" required />
                    <x-textarea name="description" label="Description" rows="2" />
                    <x-input name="contribution_amount" type="number" step="0.01" min="0" label="Default contribution" />
                    <x-select name="contribution_frequency" label="Frequency" :options="['monthly' => 'Monthly', 'weekly' => 'Weekly', 'none' => 'Ad hoc']" />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create fund</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Fund balance" :value="\App\Support\Money::format($totalBalance)" hint="Across all welfare funds" />
        <x-stat label="Funds" :value="number_format($funds->count())" hint="Active and inactive" tone="info" />
        <x-stat label="Pending claims" :value="number_format($pendingClaims)" :hint="$pendingClaims > 0 ? 'Awaiting approval' : 'Nothing pending'" :tone="$pendingClaims > 0 ? 'warning' : 'brand'" />
        <x-stat label="Claims" :value="number_format($claims->total())" hint="Across all pages" tone="neutral" />
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        @forelse ($funds as $fund)
            <x-card>
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-slate-900">{{ $fund->name }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $fund->description ?: 'No description' }}</p>
                        <p class="mt-2 text-xs text-slate-400">
                            {{ $fund->contribution_amount ? \App\Support\Money::format($fund->contribution_amount).' '.($fund->contribution_frequency ?? 'ad hoc') : 'No fixed contribution' }}
                            · {{ $fund->contributions_count }} contribution(s) · {{ $fund->claims_count }} claim(s)
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-semibold text-slate-900">@money($fund->balance)</p>
                        <x-badge :status="$fund->is_active ? 'active' : 'inactive'" />
                    </div>
                </div>

                @if ($canManage)
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                        <details class="flex-1">
                            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                <x-icon name="banknotes" class="h-3.5 w-3.5" /> Record contribution
                            </summary>
                            <form method="POST" action="{{ route('welfare.funds.contribute', $fund) }}" class="mt-2 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                @csrf
                                <x-select name="member_id" label="Member" placeholder="Select member" :options="$memberOptions" required />
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" :value="$fund->contribution_amount ? $fund->contribution_amount / 100 : null" required />
                                    <x-select name="method" label="Method" :options="['mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cash' => 'Cash']" required />
                                </div>
                                <x-input name="reference" label="Reference" />
                                <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Record contribution</button>
                            </form>
                        </details>

                        <details class="flex-1">
                            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                <x-icon name="heart" class="h-3.5 w-3.5" /> Request claim
                            </summary>
                            <form method="POST" action="{{ route('welfare.funds.claims.store', $fund) }}" class="mt-2 space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                @csrf
                                <x-select name="member_id" label="Member" placeholder="Select member" :options="$memberOptions" required />
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <x-select name="type" label="Type" :options="['funeral' => 'Funeral', 'medical' => 'Medical', 'emergency' => 'Emergency', 'other' => 'Other']" required />
                                    <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" required />
                                </div>
                                <x-textarea name="reason" label="Reason" rows="2" required />
                                <button type="submit" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">Submit claim</button>
                            </form>
                        </details>
                    </div>
                @endif
            </x-card>
        @empty
            <x-empty title="No welfare funds" message="Create a fund to start collecting contributions and paying claims." class="xl:col-span-2" />
        @endforelse
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('welfare.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
            <div class="w-full sm:w-44">
                <x-select name="status" label="Claim status" placeholder="All statuses" :options="$statusOptions" :value="request('status')" />
            </div>
            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                <x-icon name="search" class="h-4 w-4" /> Filter
            </button>
            <a href="{{ route('welfare.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
        </form>

        @if ($claims->isEmpty())
            <x-empty title="No claims" message="Claims raised against welfare funds appear here." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Fund</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3 text-right">Amount</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Requested</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($claims as $claim)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('members.show', $claim->member_id) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $claim->member?->full_name ?? '—' }}</a>
                                    <p class="text-xs text-slate-400">{{ $claim->member?->member_no }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $claim->fund?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ ucfirst($claim->type) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($claim->amount)</td>
                                <td class="px-4 py-3"><x-badge :status="$claim->status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $claim->created_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if ($canManage && $claim->status === 'pending')
                                        <details class="relative">
                                            <summary class="cursor-pointer list-none text-sm font-semibold text-brand-700 hover:text-brand-800">Review</summary>
                                            <div class="absolute right-0 z-20 mt-2 w-72 space-y-3 rounded-xl border border-slate-200 bg-white p-3 text-left shadow-lg">
                                                <p class="text-xs text-slate-500">{{ $claim->reason }}</p>
                                                <form method="POST" action="{{ route('welfare.claims.approve', $claim) }}" class="space-y-2">
                                                    @csrf
                                                    <x-input name="notes" label="Approval notes" />
                                                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('welfare.claims.reject', $claim) }}" class="space-y-2 border-t border-slate-100 pt-2">
                                                    @csrf
                                                    <x-input name="reason" label="Rejection reason" required />
                                                    <button type="submit" class="w-full rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Reject</button>
                                                </form>
                                            </div>
                                        </details>
                                    @elseif ($canManage && $claim->status === 'approved')
                                        <form method="POST" action="{{ route('welfare.claims.disburse', $claim) }}" class="inline-flex items-center gap-1.5">
                                            @csrf
                                            <select name="method" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700">
                                                <option value="cash">Cash</option>
                                                <option value="bank">Bank</option>
                                            </select>
                                            <button type="submit" class="rounded-lg bg-brand-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">Disburse</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $claims->links() }}</div>
        @endif
    </x-card>
@endsection
