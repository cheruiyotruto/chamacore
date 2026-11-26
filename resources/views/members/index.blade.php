@extends('layouts.app')

@section('title', 'Members')

@section('content')
    @php
        $can = fn (string $permission) => ($chamaContext ?? app(\App\Support\GroupContext::class))->hasPermission($permission);
        $roleOptions = $roles->pluck('name', 'key')->all();
    @endphp

    <x-page-header title="Members" :subtitle="$members->total().' member record(s) in '.($currentGroup?->name ?? 'this group')">
        @if ($can('members.export'))
            <a href="{{ route('members.export') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                <x-icon name="download" class="h-4 w-4" /> Export CSV
            </a>
        @endif
        @if ($can('members.create'))
            <a href="{{ route('members.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Add member
            </a>
        @endif
    </x-page-header>

    <x-card :padding="false">
        <form method="GET" action="{{ route('members.index') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-input name="search" placeholder="Name, member no, phone, ID" :value="request('search')" />
            <x-select name="status" placeholder="All statuses" :options="array_combine($statuses, $statuses)" :value="request('status')" />
            <x-select name="role" placeholder="All roles" :options="$roleOptions" :value="request('role')" />
            <div class="flex items-end gap-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Filter
                </button>
                <a href="{{ route('members.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if ($members->isEmpty())
            <x-empty title="No members match your filters" message="Try a different search or clear the filters." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Contact</th>
                            <th class="px-4 py-3">Role</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">KYC</th>
                            <th class="px-4 py-3">Joined</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($members as $member)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800">{{ $member->initials() }}</span>
                                        <div class="min-w-0">
                                            <a href="{{ route('members.show', $member) }}" class="block truncate font-medium text-slate-900 hover:text-brand-700">{{ $member->full_name }}</a>
                                            <p class="text-xs text-slate-500">{{ $member->member_no }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-600">
                                    <p class="text-sm">{{ $member->phone ?: '—' }}</p>
                                    <p class="text-xs text-slate-400">{{ $member->email }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ $member->role?->name ?? '—' }}</td>
                                <td class="px-4 py-3"><x-badge :status="$member->status" /></td>
                                <td class="px-4 py-3"><x-badge :status="$member->kyc_status" /></td>
                                <td class="px-4 py-3 text-xs text-slate-500">{{ $member->joined_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('members.show', $member) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">
                {{ $members->links() }}
            </div>
        @endif
    </x-card>
@endsection
