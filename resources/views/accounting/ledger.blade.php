@extends('layouts.app')

@section('title', 'General ledger')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $accountOptions = $accounts->mapWithKeys(fn ($item) => [$item->code => $item->code.' · '.$item->name])->all();
        $tone = $account ? match ($account->type) {
            'asset' => 'info',
            'liability' => 'warning',
            'equity' => 'brand',
            'income' => 'brand',
            'expense' => 'danger',
            default => 'neutral',
        } : 'neutral';
    @endphp

    <x-page-header title="General ledger" :subtitle="$account ? $account->code.' · '.$account->name : 'Select an account to view its movements'">
        <a href="{{ route('accounting.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Accounting
        </a>
        @if ($can('accounting.journal'))
            <a href="{{ route('accounting.journals.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New journal
            </a>
        @endif
    </x-page-header>

    @if ($account)
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-stat label="Account" :value="$account->code" :hint="$account->name" />
            <x-stat label="Type" :value="ucfirst($account->type)" :hint="'Normal balance: '.ucfirst($account->normal_balance)" :tone="$tone" />
            <x-stat label="Balance" :value="\App\Support\Money::format($balance)" hint="All posted movements" />
            <x-stat label="Status" :value="$account->is_active ? 'Active' : 'Inactive'" :hint="$account->is_system ? 'System account' : 'Custom account'" tone="neutral" />
        </div>
    @endif

    <x-card :padding="false">
        <form method="GET" action="{{ route('accounting.ledger') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <x-select name="account" label="Account" placeholder="Select an account" :options="$accountOptions" :value="$account?->code" />
            </div>
            <x-input name="from" type="date" label="From" :value="request('from')" />
            <x-input name="to" type="date" label="To" :value="request('to')" />
            <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Apply filters
                </button>
                <a href="{{ route('accounting.ledger') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if (! $account)
            <x-empty title="No ledger accounts" message="The chart of accounts is created when the group is provisioned." class="m-4" />
        @elseif (! $lines || $lines->isEmpty())
            <x-empty title="No movements" message="Nothing has been posted to this account for the selected period." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Entry</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3 text-right">Debit</th>
                            <th class="px-4 py-3 text-right">Credit</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($lines as $line)
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $line->journalEntry?->entry_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    @if ($line->journalEntry)
                                        <a href="{{ route('accounting.journals.show', $line->journalEntry) }}" class="font-mono text-xs font-medium text-brand-700 hover:text-brand-800">{{ $line->journalEntry->entry_no }}</a>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="max-w-md px-4 py-2.5">
                                    <p class="truncate text-slate-700">{{ $line->description ?: ($line->journalEntry?->description ?? '—') }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    @if ($line->member)
                                        <a href="{{ route('members.show', $line->member) }}" class="hover:text-brand-700">{{ $line->member->full_name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right font-medium text-slate-900">{{ $line->debit > 0 ? \App\Support\Money::format($line->debit) : '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-medium text-slate-900">{{ $line->credit > 0 ? \App\Support\Money::format($line->credit) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="4" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Page totals</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($lines->getCollection()->sum('debit'))</td>
                            <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($lines->getCollection()->sum('credit'))</td>
                        </tr>
                        <tr>
                            <td colspan="4" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Account balance</td>
                            <td colspan="2" class="px-4 py-2.5 text-right text-base font-semibold text-slate-900">@money($balance)</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $lines->links() }}</div>
        @endif
    </x-card>
@endsection
