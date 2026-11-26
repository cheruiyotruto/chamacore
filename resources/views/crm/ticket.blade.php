@extends('layouts.app')

@section('title', $ticket->subject)

@section('content')
    @php
        $statusOptions = ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
        $priorityOptions = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
        $firstMessage = $ticket->messages->first();
    @endphp

    <x-page-header :title="$ticket->subject" :subtitle="($ticket->member?->full_name ?? 'Unknown member').' · '.ucfirst($ticket->category).' · opened '.($ticket->created_at?->format('d M Y H:i') ?? '—')">
        <a href="{{ route('crm.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> CRM
        </a>
        @if ($ticket->member)
            <a href="{{ route('members.show', $ticket->member) }}" class="inline-flex items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium text-slate-600 hover:text-slate-800">
                <x-icon name="users" class="h-4 w-4" /> Member profile
            </a>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Status" :value="ucfirst(str_replace('_', ' ', $ticket->status))" :hint="$ticket->closed_at ? 'Closed '.$ticket->closed_at->format('d M Y') : 'Active ticket'" :tone="in_array($ticket->status, ['resolved', 'closed'], true) ? 'brand' : 'warning'" />
        <x-stat label="Priority" :value="ucfirst($ticket->priority)" hint="Member-selected urgency" :tone="in_array($ticket->priority, ['high', 'urgent'], true) ? 'danger' : 'info'" />
        <x-stat label="Messages" :value="number_format($ticket->messages->count())" :hint="$ticket->messages->where('is_internal', true)->count().' internal note(s)'" tone="neutral" />
        <x-stat label="Last reply" :value="$ticket->last_reply_at?->diffForHumans() ?? 'None'" :hint="$ticket->last_reply_at?->format('d M Y H:i') ?? 'No replies yet'" tone="neutral" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Conversation" :subtitle="$ticket->messages->count().' message(s)'" :padding="false">
                <div class="max-h-[520px] divide-y divide-slate-100 overflow-y-auto">
                    @forelse ($ticket->messages as $message)
                        @php $internal = (bool) $message->is_internal; @endphp
                        <div class="px-4 py-4 {{ $internal ? 'bg-amber-50/60' : '' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full {{ $internal ? 'bg-amber-200 text-amber-800' : 'bg-brand-100 text-brand-700' }} text-xs font-semibold">
                                        {{ strtoupper(substr($message->sender?->name ?? 'S', 0, 2)) }}
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900">{{ $message->sender?->name ?? 'Member' }}</p>
                                        <p class="text-xs text-slate-500">{{ $message->created_at?->format('d M Y H:i') ?? '—' }}</p>
                                    </div>
                                </div>
                                @if ($internal)
                                    <x-badge status="warning" label="Internal note" />
                                @endif
                            </div>
                            <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $message->body }}</p>
                        </div>
                    @empty
                        <x-empty title="No messages" message="The member has not added any messages yet." class="m-4" />
                    @endforelse
                </div>
            </x-card>

            @if ($canManage)
                <x-card title="Reply">
                    <form method="POST" action="{{ route('crm.tickets.reply', $ticket) }}" class="space-y-3">
                        @csrf
                        <x-textarea name="body" label="Message" rows="4" required />
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="is_internal" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            Internal note (hidden from the member)
                        </label>
                        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Send reply</button>
                    </form>
                </x-card>
            @endif
        </div>

        <div class="space-y-5">
            <x-card title="Ticket details">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Reference</dt>
                        <dd class="break-all font-mono text-xs text-slate-700">{{ $ticket->uuid }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Category</dt>
                        <dd class="font-medium text-slate-800">{{ ucfirst($ticket->category) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Status</dt>
                        <dd><x-badge :status="$ticket->status" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Priority</dt>
                        <dd><x-badge :status="$ticket->priority" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Member</dt>
                        <dd class="font-medium text-slate-800">{{ $ticket->member?->full_name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-slate-500">Opened</dt>
                        <dd class="font-medium text-slate-800">{{ $ticket->created_at?->format('d M Y H:i') ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($firstMessage)
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Member's request</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $firstMessage->body }}</p>
                    </div>
                @endif
            </x-card>

            @if ($canManage)
                <x-card title="Manage ticket">
                    <form method="POST" action="{{ route('crm.tickets.update', $ticket) }}" class="space-y-3">
                        @csrf
                        @method('PUT')
                        <x-select name="status" label="Status" :options="$statusOptions" :value="$ticket->status" required />
                        <x-select name="priority" label="Priority" :options="$priorityOptions" :value="$ticket->priority" required />
                        <x-input name="assigned_to" type="number" min="1" label="Assigned user ID" :value="$ticket->assigned_to" help="Staff user responsible for this ticket" />
                        <button type="submit" class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Save ticket</button>
                    </form>
                </x-card>
            @endif
        </div>
    </div>
@endsection
