@extends('layouts.app')

@section('title', 'Schedule a meeting')

@section('content')
    <x-page-header title="Schedule a meeting" subtitle="Members can review attendance, collections and minutes after the meeting.">
        <a href="{{ route('meetings.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All meetings
        </a>
    </x-page-header>

    <form method="POST" action="{{ route('meetings.store') }}" class="space-y-5">
        @csrf

        <x-card title="Meeting details">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-input name="title" label="Title" placeholder="e.g. Monthly general meeting" required />
                <x-select name="type" label="Meeting type" :options="['regular' => 'Regular', 'special' => 'Special', 'agm' => 'Annual general meeting', 'committee' => 'Committee', 'training' => 'Training']" required />
                <x-input name="scheduled_at" type="datetime-local" label="Starts at" :value="now()->addDay()->startOfHour()" required />
                <x-input name="location" label="Location" placeholder="Venue or online link" />
            </div>
            <div class="mt-4">
                <x-textarea name="agenda" label="Agenda" rows="6" placeholder="One item per line" />
            </div>
        </x-card>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">Attendance and collections are recorded from the meeting page.</p>
            <div class="flex items-center gap-3">
                <a href="{{ route('meetings.index') }}" class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">Cancel</a>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Schedule meeting</button>
            </div>
        </div>
    </form>
@endsection
