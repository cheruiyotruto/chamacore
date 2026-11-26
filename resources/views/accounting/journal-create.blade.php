@extends('layouts.app')

@section('title', 'New journal entry')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $accountOptions = $accounts->mapWithKeys(fn ($account) => [$account->code => $account->code.' · '.$account->name])->all();
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
        $initialLines = old('lines', [
            ['code' => '', 'debit' => '', 'credit' => '', 'member_id' => '', 'description' => ''],
            ['code' => '', 'debit' => '', 'credit' => '', 'member_id' => '', 'description' => ''],
        ]);
    @endphp

    <x-page-header title="New journal entry" :subtitle="'Double-entry posting · '.($currentGroup?->name ?? 'this group')">
        <a href="{{ route('accounting.journals') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Journal list
        </a>
    </x-page-header>

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            <p class="font-semibold">The journal could not be posted</p>
            <ul class="mt-1 list-inside list-disc space-y-0.5 text-rose-700">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('accounting.journals.store') }}" x-data="journalForm(@js($initialLines))">
        @csrf

        <div class="grid gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <x-card title="Entry details">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input name="entry_date" type="date" label="Entry date" :value="old('entry_date', now())" required />
                        <x-input name="description" label="Description" placeholder="What does this journal record?" :value="old('description')" required />
                    </div>
                </x-card>

                <x-card title="Lines" subtitle="Every entry needs at least two lines and must balance." :padding="false">
                    <x-slot:actions>
                        <button type="button" @click="addLine()" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            <x-icon name="plus" class="h-3.5 w-3.5" /> Add line
                        </button>
                    </x-slot:actions>

                    <div class="divide-y divide-slate-100">
                        <template x-for="(line, index) in lines" :key="line.key">
                            <div class="grid gap-3 p-4 sm:grid-cols-12">
                                <div class="sm:col-span-4">
                                    <label class="block text-xs font-medium text-slate-600">Account</label>
                                    <select :name="`lines[${index}][code]`" x-model="line.code" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                        <option value="">Select account</option>
                                        @foreach ($accountOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-slate-600">Debit</label>
                                    <input type="number" step="0.01" min="0" :name="`lines[${index}][debit]`" x-model="line.debit" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-right text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-medium text-slate-600">Credit</label>
                                    <input type="number" step="0.01" min="0" :name="`lines[${index}][credit]`" x-model="line.credit" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-right text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                </div>
                                <div class="sm:col-span-3">
                                    <label class="block text-xs font-medium text-slate-600">Member (optional)</label>
                                    <select :name="`lines[${index}][member_id]`" x-model="line.member_id" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                        <option value="">Not member-specific</option>
                                        @foreach ($memberOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-1 flex items-end justify-end">
                                    <button type="button" @click="removeLine(index)" :disabled="lines.length <= 2" class="inline-flex items-center justify-center rounded-lg border border-slate-200 p-2 text-slate-400 hover:border-rose-200 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-40" title="Remove line">
                                        <x-icon name="trash" class="h-4 w-4" />
                                    </button>
                                </div>
                                <div class="sm:col-span-12">
                                    <input type="text" :name="`lines[${index}][description]`" x-model="line.description" placeholder="Line description (optional)" class="block w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs text-slate-700 placeholder:text-slate-400 focus:border-brand-500 focus:bg-white focus:outline-none focus:ring-1 focus:ring-brand-500">
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                        <div class="flex flex-wrap items-center gap-4">
                            <span class="text-slate-500">Debits <span class="font-semibold text-slate-900" x-text="money(totalDebit)"></span></span>
                            <span class="text-slate-500">Credits <span class="font-semibold text-slate-900" x-text="money(totalCredit)"></span></span>
                        </div>
                        <span class="font-semibold" :class="balanced ? 'text-emerald-700' : 'text-rose-700'" x-text="balanced ? 'Balanced' : 'Out of balance by ' + money(Math.abs(totalDebit - totalCredit))"></span>
                    </div>
                </x-card>
            </div>

            <div class="space-y-5">
                <x-card title="Posting rules">
                    <ul class="space-y-2 text-sm text-slate-600">
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Each line is either a debit or a credit, never both.</li>
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Total debits must equal total credits.</li>
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Amounts are entered in {{ $currentGroup?->currency ?? 'KES' }} and posted in minor units.</li>
                        <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> Posting creates an immutable journal; corrections are made by reversal.</li>
                    </ul>
                </x-card>

                <x-card title="Actions">
                    <button type="submit" :disabled="! balanced" class="w-full rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50">
                        Post journal
                    </button>
                    <a href="{{ route('accounting.journals') }}" class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</a>
                    <p class="mt-3 text-xs text-slate-500">The server re-validates every line and refuses unbalanced journals.</p>
                </x-card>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        function journalForm(initial) {
            return {
                lines: (initial || []).map((line, index) => ({
                    key: index + '-' + Math.random().toString(36).slice(2),
                    code: line.code ?? '',
                    debit: line.debit ?? '',
                    credit: line.credit ?? '',
                    member_id: line.member_id ?? '',
                    description: line.description ?? '',
                })),
                addLine() {
                    this.lines.push({
                        key: Math.random().toString(36).slice(2),
                        code: '',
                        debit: '',
                        credit: '',
                        member_id: '',
                        description: '',
                    });
                },
                removeLine(index) {
                    if (this.lines.length > 2) {
                        this.lines.splice(index, 1);
                    }
                },
                get totalDebit() {
                    return this.lines.reduce((sum, line) => sum + (parseFloat(line.debit) || 0), 0);
                },
                get totalCredit() {
                    return this.lines.reduce((sum, line) => sum + (parseFloat(line.credit) || 0), 0);
                },
                get balanced() {
                    return this.totalDebit > 0 && Math.abs(this.totalDebit - this.totalCredit) < 0.005;
                },
                money(value) {
                    return '{{ $currentGroup?->currency ?? 'KES' }} ' + (Number(value) || 0).toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                },
            };
        }
    </script>
@endpush
