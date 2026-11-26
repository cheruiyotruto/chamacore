@extends('layouts.app')

@section('title', 'New merry-go-round cycle')

@section('content')
    <x-page-header title="New merry-go-round cycle" subtitle="Participants are rotated in order and contributions are generated for the whole cycle.">
        <a href="{{ route('mgr.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All cycles
        </a>
    </x-page-header>

    <form method="POST" action="{{ route('mgr.store') }}" class="space-y-5" x-data="{ search: '' }">
        @csrf

        <x-card title="Cycle details">
            @include('mgr._form')
        </x-card>

        <x-card title="Participants" subtitle="At least two active members are required.">
            <div class="mb-3">
                <input type="search" x-model="search" placeholder="Search members by name or number"
                       class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
            </div>

            @error('member_ids')
                <p class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ $message }}</p>
            @enderror

            @if ($members->isEmpty())
                <x-empty title="No active members" message="Add and approve members before creating a cycle." />
            @else
                <div class="grid max-h-96 gap-2 overflow-y-auto pr-1 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($members as $member)
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50"
                               x-show="search === '' || '{{ strtolower($member->full_name.' '.$member->member_no) }}'.includes(search.toLowerCase())">
                            <input type="checkbox" name="member_ids[]" value="{{ $member->id }}" @checked(in_array($member->id, old('member_ids', []))) class="rounded border-slate-300 text-brand-600">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">{{ $member->initials() }}</span>
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-slate-800">{{ $member->full_name }}</span>
                                <span class="block text-xs text-slate-500">{{ $member->member_no }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
        </x-card>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">The payout amount per participant is the contribution multiplied by the number of participants.</p>
            <div class="flex items-center gap-3">
                <a href="{{ route('mgr.index') }}" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create cycle</button>
            </div>
        </div>
    </form>
@endsection
