@extends('layouts.portal')

@section('title', 'Support')

@section('content')
    @php
        $categoryOptions = [
            'general' => 'General enquiry',
            'loan' => 'Loan',
            'savings' => 'Savings',
            'wallet' => 'Wallet',
            'account' => 'My account',
            'technical' => 'Technical issue',
        ];
        $priorityOptions = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
        $openTickets = $tickets->getCollection()->whereIn('status', ['open', 'pending', 'in_progress'])->count();
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">Support</h1>
        <p class="mt-1 text-sm text-slate-500">Ask the group office a question or raise an issue with your account.</p>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="My tickets" :subtitle="$tickets->total().' ticket(s) · '.$openTickets.' open on this page'" :padding="false">
                @if ($tickets->isEmpty())
                    <x-empty title="No support tickets" message="Use the form to open your first ticket." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($tickets as $ticket)
                            <a href="{{ route('portal.support.show', $ticket) }}" class="block px-4 py-4 hover:bg-slate-50">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-900">{{ $ticket->subject }}</p>
                                        <p class="text-xs text-slate-500">
                                            {{ $categoryOptions[$ticket->category] ?? ucfirst($ticket->category) }}
                                            · Opened {{ $ticket->created_at?->format('d M Y H:i') ?? '—' }}
                                            @if ($ticket->last_reply_at)
                                                · Last reply {{ $ticket->last_reply_at->format('d M Y H:i') }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-badge :status="$ticket->priority" :label="ucfirst($ticket->priority).' priority'" />
                                        <x-badge :status="$ticket->status" />
                                        <x-icon name="arrow-right" class="h-4 w-4 text-slate-400" />
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $tickets->links() }}</div>
                @endif
            </x-card>

            <x-card title="What to include" subtitle="A clear ticket gets resolved faster.">
                <ul class="space-y-2 text-sm text-slate-600">
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> The loan, savings account or wallet transaction reference.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> The date and amount involved, where relevant.</li>
                    <li class="flex gap-2"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /> What you have already tried and the outcome you expect.</li>
                </ul>
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Open a new ticket">
                <form method="POST" action="{{ route('portal.support.store') }}" class="space-y-3">
                    @csrf
                    <x-input name="subject" label="Subject" placeholder="Short summary" required />
                    <x-select name="category" label="Category" :options="$categoryOptions" required />
                    <x-select name="priority" label="Priority" :options="$priorityOptions" :value="'normal'" required />
                    <x-textarea name="message" label="Describe your issue" rows="5" required />
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="chat" class="h-4 w-4" /> Submit ticket
                    </button>
                </form>
            </x-card>

            <x-card title="Urgent matters">
                <p class="text-sm text-slate-600">If money has left your account unexpectedly, contact the treasurer or group office directly as well as opening a ticket.</p>
            </x-card>
        </div>
    </div>
@endsection
