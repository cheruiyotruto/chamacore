@extends('layouts.platform')

@section('title', 'Platform revenue')

@section('content')
    <x-page-header title="Platform revenue"
        subtitle="Income reported by organisations marked for platform oversight or full control, alongside subscription fees." />

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <x-stat label="Managed group income" :value="\App\Support\Money::format($groupIncome)" hint="Posted income accounts in marked tenants" tone="info" />
        <x-stat label="Subscription fees" :value="\App\Support\Money::format($subscriptionIncome)" hint="Paid invoices across the platform" tone="brand" />
        <x-stat label="Total platform income" :value="\App\Support\Money::format($totalIncome)" hint="Managed income plus subscription fees" />
    </div>

    <div class="mt-6 grid gap-5 xl:grid-cols-3">
        <x-card class="xl:col-span-2" title="Marked organisations" :subtitle="$groups->count().' record(s)'" :padding="false">
            @if ($groups->isEmpty())
                <x-empty title="Nothing marked yet" message="Open a group and set Platform management to oversight or full control." class="m-4" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Organisation</th>
                                <th class="px-4 py-3">Mode</th>
                                <th class="px-4 py-3 text-right">Tenants</th>
                                <th class="px-4 py-3 text-right">Members</th>
                                <th class="px-4 py-3 text-right">Income</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($groups as $row)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('platform.groups.show', $row['group']) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $row['group']->name }}</a>
                                        <p class="text-xs text-slate-400">{{ $row['group']->isBranch() ? 'Branch' : 'Organisation' }} · {{ $row['group']->slug }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">{{ $row['mode'] }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ $row['group_count'] }}</td>
                                    <td class="px-4 py-3 text-right text-slate-600">{{ number_format($row['group']->members_count) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($row['income'])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        <x-card title="Income accounts" :subtitle="$accounts->count().' account(s)'" :padding="false">
            @if ($accounts->isEmpty())
                <x-empty title="No posted income yet" message="Posted ledger income appears here once marked groups record revenue." class="m-4" />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($accounts as $account)
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div>
                                <p class="text-sm font-medium text-slate-800">{{ $account->name }}</p>
                                <p class="text-xs text-slate-400">{{ $account->code }}</p>
                            </div>
                            <p class="text-sm font-semibold text-slate-900">@money($account->total)</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>
    </div>
@endsection
