@extends('layouts.app')

@section('title', 'Edit '.$cycle->name)

@section('content')
    <x-page-header :title="'Edit '.$cycle->name" subtitle="Adjust the contribution amount, frequency, collection day, dates and payout rules.">
        <a href="{{ route('mgr.show', $cycle) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> Back to cycle
        </a>
    </x-page-header>

    <form method="POST" action="{{ route('mgr.update', $cycle) }}" class="space-y-5">
        @csrf
        @method('PATCH')

        <x-card title="Cycle details">
            @include('mgr._form')
        </x-card>

        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">What happens when you save</p>
            <p class="mt-1">
                Unpaid contributions and pending payouts are regenerated from the new schedule. Paid contributions and
                released payouts keep their original amounts and dates. Completed or cancelled cycles cannot be edited.
            </p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">{{ $cycle->participants_count }} participant(s) in this rotation.</p>
            <div class="flex items-center gap-3">
                <a href="{{ route('mgr.show', $cycle) }}" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save changes</button>
            </div>
        </div>
    </form>
@endsection
