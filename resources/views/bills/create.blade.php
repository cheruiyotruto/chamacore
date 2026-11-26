@extends('layouts.app')

@section('title', 'New bill')

@section('content')
    <x-page-header title="New bill" subtitle="Bill a member or record a customer account balance." />

    <div class="mt-6 max-w-3xl">
        <x-card title="Bill details">
            <form method="POST" action="{{ route('bills.store') }}" class="space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-select name="member_id" label="Member account (optional)" :options="['' => 'Customer / external account'] + $members->mapWithKeys(fn ($m) => [$m->id => $m->full_name])->all()" :value="old('member_id')" />
                    <x-input name="payee_name" label="Payee name" :value="old('payee_name')" required />
                    <x-input name="payee_account" label="Customer / account number" :value="old('payee_account')" />
                    <x-input name="phone" label="Phone number" :value="old('phone')" />
                    <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount" :value="old('amount')" required />
                    <x-input name="due_date" type="date" label="Due date" :value="old('due_date')" />
                    <x-select name="status" label="Initial status" :options="array_combine($statuses, $statuses)" :value="old('status', 'pending')" required />
                </div>
                <x-textarea name="description" label="Description" rows="3" :value="old('description')" />
                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create bill</button>
                    <a href="{{ route('bills.index') }}" class="text-sm font-semibold text-slate-600 hover:text-slate-900">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
@endsection
