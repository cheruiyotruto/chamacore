@extends('layouts.app')

@section('title', 'Journal '.$entry->entry_no)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $totalDebit = $entry->totalDebit();
        $totalCredit = $entry->totalCredit();
        $balanced = $totalDebit === $totalCredit;
    @endphp

    <x-page-header :title="'Journal '.$entry->entry_no" :subtitle="($entry->entry_date?->format('d M Y') ?? '—').' · '.$entry->description">
        <a href="{{ route('accounting.journals') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All journals
        </a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Total debit" :value="\App\Support\Money::format($totalDebit)" hint="Sum of debit lines" />
        <x-stat label="Total credit" :value="\App\Support\Money::format($totalCredit)" hint="Sum of credit lines" tone="info" />
        <x-stat label="Difference" :value="\App\Support\Money::format($totalDebit - $totalCredit)" :hint="$balanced ? 'Journal is balanced' : 'Journal does not balance'" :tone="$balanced ? 'brand' : 'danger'" />
        <x-stat label="Status" :value="ucfirst($entry->status)" :hint="$entry->posted_at?->format('d M Y H:i') ?? 'Not posted'" tone="neutral" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Lines" :padding="false">
                @if ($entry->lines->isEmpty())
                    <x-empty title="No lines" class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">Account</th>
                                    <th class="px-4 py-2.5">Member</th>
                                    <th class="px-4 py-2.5">Description</th>
                                    <th class="px-4 py-2.5 text-right">Debit</th>
                                    <th class="px-4 py-2.5 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($entry->lines as $line)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-2.5">
                                            <p class="font-medium text-slate-800">{{ $line->account?->name ?? '—' }}</p>
                                            <p class="font-mono text-xs text-slate-400">{{ $line->account?->code }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">
                                            @if ($line->member)
                                                <a href="{{ route('members.show', $line->member) }}" class="hover:text-brand-700">{{ $line->member->full_name }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5 text-slate-600">{{ $line->description ?: '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">{{ $line->debit > 0 ? \App\Support\Money::format($line->debit) : '—' }}</td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900">{{ $line->credit > 0 ? \App\Support\Money::format($line->credit) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-slate-50">
                                <tr>
                                    <td colspan="3" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Totals</td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($totalDebit)</td>
                                    <td class="px-4 py-2.5 text-right font-semibold text-slate-900">@money($totalCredit)</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </x-card>

            @if ($can('accounting.journal') && $entry->status !== 'reversed')
                <x-card title="Reverse this journal" subtitle="Reversal posts an equal and opposite journal and marks this entry reversed.">
                    <form method="POST" action="{{ route('accounting.journals.reverse', $entry) }}" class="space-y-3" onsubmit="return confirm('Post a reversing journal for {{ $entry->entry_no }}?');">
                        @csrf
                        <x-textarea name="reason" label="Reason" rows="2" required />
                        <button type="submit" class="rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Post reversal</button>
                    </form>
                </x-card>
            @endif
        </div>

        <div class="space-y-5">
            <x-card title="Entry details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Entry number</dt>
                        <dd class="font-mono text-xs font-medium text-slate-800">{{ $entry->entry_no }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Date</dt>
                        <dd class="font-medium text-slate-800">{{ $entry->entry_date?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$entry->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Posted by</dt>
                        <dd class="font-medium text-slate-800">{{ $entry->postedBy?->name ?? 'System' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Posted at</dt>
                        <dd class="font-medium text-slate-800">{{ $entry->posted_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                    @if ($entry->source_type)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Source</dt>
                            <dd class="text-right font-medium text-slate-800">{{ class_basename($entry->source_type) }} #{{ $entry->source_id }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4 border-t border-slate-100 pt-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</p>
                    <p class="mt-1 text-sm text-slate-700">{{ $entry->description }}</p>
                </div>
            </x-card>

            @if ($entry->reversalOf)
                <x-card title="Reversal of">
                    <a href="{{ route('accounting.journals.show', $entry->reversalOf) }}" class="text-sm font-semibold text-brand-700 hover:text-brand-800">{{ $entry->reversalOf->entry_no }}</a>
                    <p class="mt-1 text-xs text-slate-500">{{ $entry->reversalOf->description }}</p>
                </x-card>
            @endif

            @if ($can('accounting.journal'))
                <x-card title="Related">
                    <a href="{{ route('accounting.ledger', ['account' => $entry->lines->first()?->account?->code]) }}" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <x-icon name="book" class="h-4 w-4" /> Open general ledger
                    </a>
                </x-card>
            @endif
        </div>
    </div>
@endsection
