@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    @php
        $channelOptions = collect($channels)->mapWithKeys(fn ($channel) => [$channel => ucfirst(str_replace('_', ' ', $channel))])->all();
        $statusOptions = collect($statuses)->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all();
        $failed = $logs->getCollection()->where('status', 'failed')->count();
    @endphp

    <x-page-header title="Notifications" :subtitle="$logs->total().' delivery log(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($canManage)
            <form method="POST" action="{{ route('notifications.reminders.run') }}">
                @csrf
                <button class="rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Run reminders now
                </button>
            </form>
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="bell" class="h-4 w-4" /> Send announcement
                </summary>
                <form method="POST" action="{{ route('notifications.send') }}" class="absolute right-0 z-20 mt-2 w-96 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg" x-data="{ audience: 'all' }">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Broadcast an announcement</p>
                    <x-input name="subject" label="Subject" required />
                    <x-textarea name="body" label="Message" rows="4" required />
                    <x-select name="audience" label="Audience" :options="['all' => 'All active members', 'selected' => 'Selected members']" x-model="audience" required />
                    <div x-show="audience === 'selected'" x-cloak>
                        <p class="mb-1 text-sm font-medium text-slate-700">Recipients</p>
                        <div class="max-h-48 overflow-y-auto rounded-lg border border-slate-200 p-2">
                            @forelse ($members as $member)
                                <label class="flex items-center gap-2 px-1 py-1 text-xs text-slate-600">
                                    <input type="checkbox" name="member_ids[]" value="{{ $member->id }}" class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    <span>{{ $member->full_name }} <span class="text-slate-400">{{ $member->member_no }}</span></span>
                                </label>
                            @empty
                                <p class="px-1 py-2 text-xs text-slate-500">No active members.</p>
                            @endforelse
                        </div>
                    </div>
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Send announcement</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Deliveries" :value="number_format($logs->total())" hint="Across all pages" />
        <x-stat label="Sent" :value="number_format($logs->getCollection()->where('status', 'sent')->count())" hint="On this page" tone="brand" />
        <x-stat label="Failed" :value="number_format($failed)" :hint="$failed > 0 ? 'Review failures below' : 'No failures on this page'" :tone="$failed > 0 ? 'danger' : 'neutral'" />
        <x-stat label="Communications" :value="number_format($communications->count())" hint="Recent member messages" tone="info" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card :padding="false">
                <form method="GET" action="{{ route('notifications.index') }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                    <div class="w-full sm:w-40">
                        <x-select name="channel" label="Channel" placeholder="All channels" :options="$channelOptions" :value="request('channel')" />
                    </div>
                    <div class="w-full sm:w-40">
                        <x-select name="status" label="Status" placeholder="All statuses" :options="$statusOptions" :value="request('status')" />
                    </div>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        <x-icon name="search" class="h-4 w-4" /> Filter
                    </button>
                    <a href="{{ route('notifications.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
                </form>

                @if ($logs->isEmpty())
                    <x-empty title="No notifications" message="Delivery logs appear here after messages are sent." class="m-4" />
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">When</th>
                                    <th class="px-4 py-3">Channel</th>
                                    <th class="px-4 py-3">Recipient</th>
                                    <th class="px-4 py-3">Template</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3">Detail</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($logs as $log)
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $log->created_at?->format('d M Y H:i') ?? '—' }}</td>
                                        <td class="px-4 py-2.5"><x-badge status="info" :label="ucfirst(str_replace('_', ' ', $log->channel))" /></td>
                                        <td class="px-4 py-2.5">
                                            @if ($log->member)
                                                <a href="{{ route('members.show', $log->member) }}" class="font-medium text-slate-800 hover:text-brand-700">{{ $log->member->full_name }}</a>
                                            @else
                                                <span class="text-slate-600">{{ $log->recipient ?: '—' }}</span>
                                            @endif
                                            <p class="text-xs text-slate-400">{{ $log->recipient }}</p>
                                        </td>
                                        <td class="px-4 py-2.5 font-mono text-xs text-slate-600">{{ $log->template_key ?: '—' }}</td>
                                        <td class="px-4 py-2.5"><x-badge :status="$log->status" /></td>
                                        <td class="max-w-xs px-4 py-2.5 text-xs text-slate-500">
                                            {{ $log->error ?: ($log->provider_reference ?: '—') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="border-t border-slate-200 px-4 py-3">{{ $logs->links() }}</div>
                @endif
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Recent member communications" :subtitle="$communications->count().' message(s)'" :padding="false">
                @if ($communications->isEmpty())
                    <x-empty title="No messages" message="Announcements and member messages appear here." class="m-4" />
                @else
                    <div class="max-h-[540px] divide-y divide-slate-100 overflow-y-auto">
                        @foreach ($communications as $message)
                            <div class="px-4 py-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-800">{{ $message->subject ?: ucfirst($message->channel) }}</p>
                                    <span class="whitespace-nowrap text-xs text-slate-400">{{ $message->created_at?->format('d M H:i') ?? '—' }}</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $message->body }}</p>
                                <p class="mt-1 text-xs text-slate-400">
                                    {{ $message->member?->full_name ?? 'All members' }} · {{ ucfirst($message->channel) }} · {{ $message->direction }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Date reminders" subtitle="Admin-set dates for merry-go-round, loans, bills and custom notices.">
                @if ($canManage)
                    <details class="mb-4 rounded-lg bg-slate-50 p-3">
                        <summary class="cursor-pointer text-sm font-semibold text-slate-800">New date reminder</summary>
                        <div class="mt-3">
                            @include('notifications.partials.rule-form', [
                                'rule' => null,
                                'action' => route('notifications.reminders.store'),
                                'method' => 'POST',
                                'submitLabel' => 'Create reminder',
                            ])
                        </div>
                    </details>
                @endif

                @if ($rules->isEmpty())
                    <x-empty title="No group reminders" message="The built-in platform defaults are active until you add a group rule." />
                @else
                    <div class="space-y-3">
                        @foreach ($rules as $rule)
                            <div class="rounded-lg border border-slate-200 p-3">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900">{{ $rule->name }}</p>
                                        <p class="mt-0.5 text-xs text-slate-500">{{ $rule->typeLabel() }} · {{ $rule->scheduleLabel() }}</p>
                                        <p class="mt-0.5 text-xs text-slate-400">{{ ucfirst($rule->audience) }} · {{ implode(', ', $rule->channels ?? []) }}</p>
                                    </div>
                                    <x-badge :status="$rule->is_active ? 'active' : 'inactive'" />
                                </div>

                                @if ($canManage)
                                    <div class="mt-3 flex flex-wrap items-center gap-3">
                                        <form method="POST" action="{{ route('notifications.reminders.toggle', $rule) }}">
                                            @csrf
                                            <button class="text-xs font-semibold text-brand-700 hover:underline">
                                                {{ $rule->is_active ? 'Disable' : 'Enable' }}
                                            </button>
                                        </form>
                                        <details class="flex-1">
                                            <summary class="cursor-pointer text-xs font-semibold text-slate-600 hover:text-slate-900">Edit</summary>
                                            <div class="mt-3 rounded-lg border border-slate-200 bg-white p-3">
                                                @include('notifications.partials.rule-form', [
                                                    'rule' => $rule,
                                                    'action' => route('notifications.reminders.update', $rule),
                                                    'method' => 'PUT',
                                                    'submitLabel' => 'Save reminder',
                                                ])
                                            </div>
                                        </details>
                                        <form method="POST" action="{{ route('notifications.reminders.destroy', $rule) }}"
                                            onsubmit="return confirm('Remove this reminder rule?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-xs font-semibold text-rose-700 hover:underline">Remove</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            <x-card title="Channels">
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($channels as $channel)
                        <x-badge status="neutral" :label="ucfirst(str_replace('_', ' ', $channel))" />
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-500">Delivery providers are configured in <span class="font-mono">config/chama.php</span>. Failed deliveries keep their provider error in the log above.</p>
            </x-card>
        </div>
    </div>
@endsection
