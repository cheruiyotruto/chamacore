@extends('layouts.platform')

@section('title', 'Membership applications')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Membership applications</h1>
        <p class="mt-1 text-sm text-slate-500">Applications submitted through public join pages and invitation links across platform-managed groups.</p>
    </div>

    <x-card :padding="false">
        @if ($members->isEmpty())
            <x-empty title="No pending applications" message="New member applications will appear here for review." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Applicant</th>
                            <th class="px-4 py-3">Group</th>
                            <th class="px-4 py-3">Applied</th>
                            <th class="px-4 py-3">Decision</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($members as $member)
                            <tr class="align-top hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900">{{ $member->full_name }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $member->member_no }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ $member->email ?: $member->phone ?: 'No contact' }}</p>
                                    @if ($member->national_id)
                                        <p class="text-xs text-slate-400">ID {{ $member->national_id }}</p>
                                    @endif
                                    @if (data_get($member->metadata ?? [], 'kra_pin_number'))
                                        <p class="text-xs text-slate-400">KRA PIN {{ data_get($member->metadata, 'kra_pin_number') }}</p>
                                    @endif
                                    @if (data_get($member->metadata ?? [], 'application_source'))
                                        <p class="mt-1 text-xs font-semibold text-ocean-700">{{ data_get($member->metadata, 'application_source') }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900">{{ $member->group?->name ?? '—' }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500">{{ $member->role?->name ?? 'Member' }}</p>
                                    @if ($member->group?->public_join_enabled)
                                        <span class="mt-1 inline-flex rounded-full bg-jade-50 px-2 py-0.5 text-[11px] font-bold text-jade-700">Public join</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">
                                    {{ $member->created_at?->format('d M Y, H:i') ?? '—' }}
                                    <p class="mt-1">{{ ucfirst($member->status) }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex min-w-[16rem] flex-col gap-2">
                                        <form method="POST" action="{{ route('platform.applications.approve', $member) }}" class="space-y-2">
                                            @csrf
                                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                                <input type="checkbox" name="override_documents" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                Approve without required documents
                                            </label>
                                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700">
                                                <x-icon name="check" class="h-3.5 w-3.5" /> Approve
                                            </button>
                                        </form>

                                        <details class="relative">
                                            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                                <x-icon name="x" class="h-3.5 w-3.5" /> Reject
                                            </summary>
                                            <form method="POST" action="{{ route('platform.applications.reject', $member) }}" class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-3 shadow-lg" onsubmit="return confirm('Reject this application?');">
                                                @csrf
                                                <label class="block text-xs font-semibold text-slate-700" for="reason-{{ $member->id }}">Reason</label>
                                                <input id="reason-{{ $member->id }}" name="reason" required maxlength="500" class="w-full rounded-lg border-slate-300 text-xs focus:border-brand-500 focus:ring-brand-500" placeholder="Recorded in the audit trail">
                                                <button type="submit" class="w-full rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-700">Reject application</button>
                                            </form>
                                        </details>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>

    <div class="mt-4">{{ $members->links() }}</div>
@endsection
