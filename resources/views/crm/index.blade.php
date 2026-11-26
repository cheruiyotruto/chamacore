@extends('layouts.app')

@section('title', 'CRM')

@section('content')
    @php
        $leadStatusOptions = collect($leadStatuses)->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all();
        $ticketStatusOptions = collect($ticketStatuses)->mapWithKeys(fn ($status) => [$status => ucfirst(str_replace('_', ' ', $status))])->all();
        $overdueFollowUps = $followUps->filter(fn ($followUp) => $followUp->due_at && $followUp->due_at->isPast())->count();
        $openTickets = $tickets->getCollection()->whereIn('status', ['open', 'in_progress'])->count();
        $tabs = ['leads' => 'Leads', 'tickets' => 'Tickets', 'follow-ups' => 'Follow-ups', 'activity' => 'Activity'];
    @endphp

    <x-page-header title="CRM" :subtitle="'Leads, support tickets and member communications · '.($currentGroup?->name ?? 'this group')">
        @if ($canManage)
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> Capture lead
                </summary>
                <form method="POST" action="{{ route('crm.leads.store') }}" class="absolute right-0 z-20 mt-2 w-80 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">New prospect</p>
                    <x-input name="name" label="Name" required />
                    <x-input name="phone" label="Phone" required />
                    <x-input name="email" type="email" label="Email" />
                    <x-select name="source" label="Source" :options="['walk_in' => 'Walk-in', 'referral' => 'Referral', 'online' => 'Online', 'campaign' => 'Campaign']" required />
                    <x-input name="follow_up_at" type="datetime-local" label="Follow up at" />
                    <x-textarea name="notes" label="Notes" rows="2" />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save lead</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Leads" :value="number_format($leads->total())" hint="Across all pages" />
        <x-stat label="Open tickets" :value="number_format($openTickets)" hint="On this page" :tone="$openTickets > 0 ? 'warning' : 'neutral'" />
        <x-stat label="Follow-ups due" :value="number_format($overdueFollowUps)" :hint="$overdueFollowUps > 0 ? 'Overdue — action needed' : 'Nothing overdue'" :tone="$overdueFollowUps > 0 ? 'danger' : 'brand'" />
        <x-stat label="Communications" :value="number_format($communications->count())" hint="Recent messages" tone="info" />
    </div>

    <div x-data="{ tab: 'leads' }" class="space-y-5">
        <div class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm">
            @foreach ($tabs as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'" :class="tab === '{{ $key }}' ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50'" class="rounded-lg px-3.5 py-2 text-sm font-semibold transition">{{ $label }}</button>
            @endforeach
        </div>

        <div x-show="tab === 'leads'" x-cloak class="space-y-5">
            <x-card :padding="false">
                <form method="GET" action="{{ route('crm.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                    <div class="w-full sm:w-44">
                        <x-select name="status" label="Lead status" placeholder="All statuses" :options="$leadStatusOptions" :value="request('status')" />
                    </div>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        <x-icon name="search" class="h-4 w-4" /> Filter
                    </button>
                    <a href="{{ route('crm.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
                </form>

                @if ($leads->isEmpty())
                    <x-empty title="No leads" message="Capture a prospect to start tracking the pipeline." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($leads as $lead)
                            <details class="group">
                                <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900">{{ $lead->name }}</p>
                                        <p class="text-xs text-slate-500">{{ $lead->phone }}{{ $lead->email ? ' · '.$lead->email : '' }} · {{ ucfirst(str_replace('_', ' ', $lead->source)) }}</p>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs text-slate-500">{{ $lead->assignee?->name ?? 'Unassigned' }}</span>
                                        <x-badge :status="$lead->status" />
                                        <x-icon name="arrow-right" class="h-4 w-4 text-slate-400 transition group-open:rotate-90" />
                                    </div>
                                </summary>
                                <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-4">
                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</p>
                                            <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $lead->notes ?: 'No notes yet.' }}</p>
                                            <p class="mt-2 text-xs text-slate-400">
                                                Follow up: {{ $lead->follow_up_at?->format('d M Y H:i') ?? 'not scheduled' }}
                                                @if ($lead->convertedMember)
                                                    · Converted to {{ $lead->convertedMember->full_name }}
                                                @endif
                                            </p>
                                        </div>

                                        @if ($canManage)
                                            <div class="space-y-4">
                                                <form method="POST" action="{{ route('crm.leads.update', $lead) }}" class="space-y-3 rounded-lg border border-slate-200 bg-white p-3">
                                                    @csrf
                                                    @method('PUT')
                                                    <x-select name="status" label="Status" :options="$leadStatusOptions" :value="$lead->status" required />
                                                    <x-input name="follow_up_at" type="datetime-local" label="Follow up at" :value="$lead->follow_up_at" />
                                                    <x-textarea name="notes" label="Notes" rows="2" :value="$lead->notes" />
                                                    <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-700">Update lead</button>
                                                </form>

                                                <form method="POST" action="{{ route('crm.follow-ups.store', $lead) }}" class="space-y-2 rounded-lg border border-slate-200 bg-white p-3">
                                                    @csrf
                                                    <p class="text-sm font-semibold text-slate-900">Schedule a follow-up</p>
                                                    <x-input name="due_at" type="datetime-local" label="Due at" required />
                                                    <x-textarea name="notes" label="Notes" rows="2" />
                                                    <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Schedule</button>
                                                </form>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </details>
                        @endforeach
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $leads->links() }}</div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'tickets'" x-cloak>
            <x-card :padding="false">
                <form method="GET" action="{{ route('crm.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                    <div class="w-full sm:w-44">
                        <x-select name="ticket_status" label="Ticket status" placeholder="All statuses" :options="$ticketStatusOptions" :value="request('ticket_status')" />
                    </div>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        <x-icon name="search" class="h-4 w-4" /> Filter
                    </button>
                    <a href="{{ route('crm.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
                </form>

                @if ($tickets->isEmpty())
                    <x-empty title="No tickets" message="Member support tickets raised in the portal appear here." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Subject</th>
                                    <th class="px-4 py-3">Member</th>
                                    <th class="px-4 py-3">Category</th>
                                    <th class="px-4 py-3">Priority</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Last reply</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($tickets as $ticket)
                                    <tr class="hover:bg-slate-50">
                                        <td class="max-w-xs px-4 py-3">
                                            <a href="{{ route('crm.tickets.show', $ticket) }}" class="block truncate font-medium text-slate-800 hover:text-brand-700">{{ $ticket->subject }}</a>
                                            <p class="font-mono text-xs text-slate-400">{{ $ticket->uuid }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-slate-600">{{ $ticket->member?->full_name ?? '—' }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ ucfirst($ticket->category) }}</td>
                                        <td class="px-4 py-3"><x-badge :status="$ticket->priority" /></td>
                                        <td class="px-4 py-3"><x-badge :status="$ticket->status" /></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $ticket->last_reply_at?->format('d M Y H:i') ?? '—' }}</td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('crm.tickets.show', $ticket) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $tickets->links() }}</div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'follow-ups'" x-cloak>
            <x-card title="Open follow-ups" :subtitle="$followUps->count().' pending'" :padding="false">
                @if ($followUps->isEmpty())
                    <x-empty title="No follow-ups" message="Scheduled follow-ups appear here until completed." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($followUps as $followUp)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div>
                                    <p class="text-sm font-semibold text-slate-900">{{ $followUp->lead?->name ?? 'Lead removed' }}</p>
                                    <p class="text-xs text-slate-500">{{ $followUp->notes ?: 'No notes' }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs {{ $followUp->due_at?->isPast() ? 'font-semibold text-rose-700' : 'text-slate-500' }}">
                                        Due {{ $followUp->due_at?->format('d M Y H:i') ?? '—' }}
                                    </span>
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('crm.follow-ups.complete', $followUp) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Mark complete</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>

        <div x-show="tab === 'activity'" x-cloak>
            <x-card title="Recent communications" :subtitle="$communications->count().' message(s)'" :padding="false">
                @if ($communications->isEmpty())
                    <x-empty title="No communications" message="Messages sent to members appear here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($communications as $message)
                            <div class="px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800">{{ $message->subject ?: ucfirst($message->channel) }}</p>
                                        <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ $message->body }}</p>
                                    </div>
                                    <span class="whitespace-nowrap text-xs text-slate-400">{{ $message->created_at?->format('d M H:i') ?? '—' }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-400">
                                    {{ $message->member?->full_name ?? 'All members' }} · {{ ucfirst($message->channel) }} · {{ $message->direction }} · {{ $message->status }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </div>
    </div>
@endsection
