@extends('layouts.portal')

@section('title', $ticket->subject)

@section('content')
    @php
        $categoryLabels = [
            'general' => 'General enquiry',
            'loan' => 'Loan',
            'savings' => 'Savings',
            'wallet' => 'Wallet',
            'account' => 'My account',
            'technical' => 'Technical issue',
        ];
        $isClosed = in_array($ticket->status, ['closed', 'resolved'], true);
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('portal.support.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-700">
                <x-icon name="arrow-left" class="h-4 w-4" /> All tickets
            </a>
            <h1 class="mt-2 text-xl font-semibold tracking-tight text-slate-900">{{ $ticket->subject }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                {{ $categoryLabels[$ticket->category] ?? ucfirst($ticket->category) }}
                · Opened {{ $ticket->created_at?->format('d M Y H:i') ?? '—' }}
                · Ref <span class="font-mono">{{ $ticket->uuid }}</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-badge :status="$ticket->priority" :label="ucfirst($ticket->priority).' priority'" />
            <x-badge :status="$ticket->status" />
        </div>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-card title="Conversation" :subtitle="$ticket->messages->count().' message(s)'">
                @if ($ticket->messages->isEmpty())
                    <x-empty title="No messages" message="The conversation has not started yet." />
                @else
                    <div class="space-y-4">
                        @foreach ($ticket->messages as $message)
                            @php $isMine = $message->sender_id === auth()->id(); @endphp
                            <div class="flex {{ $isMine ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[85%] rounded-xl px-4 py-3 {{ $isMine ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-800' }}">
                                    <p class="text-xs font-semibold {{ $isMine ? 'text-brand-100' : 'text-slate-500' }}">
                                        {{ $isMine ? 'You' : ($message->sender?->name ?? 'Support team') }}
                                        <span class="font-normal opacity-75">· {{ $message->created_at?->format('d M Y H:i') ?? '—' }}</span>
                                    </p>
                                    <p class="mt-1 whitespace-pre-line text-sm">{{ $message->body }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($isClosed)
                    <p class="mt-5 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        This ticket is {{ $ticket->status }}. Open a new ticket if you need further help.
                    </p>
                @else
                    <form method="POST" action="{{ route('portal.support.reply', $ticket) }}" class="mt-5 border-t border-slate-100 pt-4">
                        @csrf
                        <x-textarea name="body" label="Reply" rows="4" required />
                        <button type="submit" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                            <x-icon name="chat" class="h-4 w-4" /> Send reply
                        </button>
                    </form>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Ticket details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$ticket->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Priority</dt>
                        <dd class="font-medium text-slate-800">{{ ucfirst($ticket->priority) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Category</dt>
                        <dd class="font-medium text-slate-800">{{ $categoryLabels[$ticket->category] ?? ucfirst($ticket->category) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Opened</dt>
                        <dd class="font-medium text-slate-800">{{ $ticket->created_at?->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Last reply</dt>
                        <dd class="font-medium text-slate-800">{{ $ticket->last_reply_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Need to add something?">
                <p class="text-sm text-slate-600">Reply in this conversation so the office keeps the full history in one place. Replies reopen resolved tickets automatically.</p>
            </x-card>
        </div>
    </div>
@endsection
