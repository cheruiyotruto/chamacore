@extends('layouts.app')

@section('title', 'Documents')

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $typeOptions = collect($types)->mapWithKeys(fn ($type) => [$type => ucfirst(str_replace('_', ' ', $type))])->all();
        $statusOptions = collect($statuses)->mapWithKeys(fn ($status) => [$status => ucfirst($status)])->all();
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
        $pending = $documents->getCollection()->where('status', 'pending')->count();
    @endphp

    <x-page-header title="Documents" :subtitle="$documents->total().' document(s) · '.($currentGroup?->name ?? 'this group')">
        @if ($can('documents.manage'))
            <details class="relative">
                <summary class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    <x-icon name="plus" class="h-4 w-4" /> Upload document
                </summary>
                <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="absolute right-0 z-20 mt-2 w-96 space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-left shadow-lg">
                    @csrf
                    <p class="text-sm font-semibold text-slate-900">Upload a document</p>
                    <x-input name="file" type="file" label="File" required />
                    <x-input name="title" label="Title" placeholder="Optional display name" />
                    <x-select name="type" label="Type" :options="$typeOptions" required />
                    <x-select name="member_id" label="Member" placeholder="Not member-specific" :options="$memberOptions" />
                    <x-select name="visibility" label="Visibility" :options="['staff' => 'Staff only', 'member' => 'Member', 'public' => 'Public']" required />
                    <x-input name="expires_at" type="date" label="Expires at" />
                    <x-textarea name="notes" label="Notes" rows="2" />
                    <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">Upload</button>
                </form>
            </details>
        @endif
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Documents" :value="number_format($documents->total())" hint="Across all pages" />
        <x-stat label="Pending review" :value="number_format($pending)" hint="On this page" tone="warning" />
        <x-stat label="Verified" :value="number_format($documents->getCollection()->where('status', 'verified')->count())" hint="On this page" tone="brand" />
        <x-stat label="Members" :value="number_format($members->count())" hint="Document owners" tone="neutral" />
    </div>

    <x-card :padding="false">
        <form method="GET" action="{{ route('documents.index') }}" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-input name="search" placeholder="Title or member" :value="request('search')" />
            <x-select name="type" placeholder="All types" :options="$typeOptions" :value="request('type')" />
            <x-select name="status" placeholder="All statuses" :options="$statusOptions" :value="request('status')" />
            <x-select name="member_id" placeholder="All members" :options="$memberOptions" :value="request('member_id')" />
            <div class="flex items-end gap-2">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="search" class="h-4 w-4" /> Filter
                </button>
                <a href="{{ route('documents.index') }}" class="rounded-lg px-2 py-2 text-sm font-medium text-slate-500 hover:text-slate-700">Reset</a>
            </div>
        </form>

        @if ($documents->isEmpty())
            <x-empty title="No documents" message="Upload member documents, minutes, policies and receipts here." class="m-4" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Document</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Member</th>
                            <th class="px-4 py-3">Visibility</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Uploaded</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($documents as $document)
                            <tr class="hover:bg-slate-50">
                                <td class="max-w-xs px-4 py-3">
                                    <a href="{{ route('documents.show', $document) }}" class="block truncate font-medium text-slate-800 hover:text-brand-700">{{ $document->title }}</a>
                                    <p class="text-xs text-slate-400">{{ $document->mime_type ?: 'unknown type' }}{{ $document->size ? ' · '.number_format($document->size / 1024, 1).' KB' : '' }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-600">{{ ucfirst(str_replace('_', ' ', $document->type)) }}</td>
                                <td class="px-4 py-3 text-slate-600">
                                    @if ($document->member)
                                        <a href="{{ route('members.show', $document->member) }}" class="hover:text-brand-700">{{ $document->member->full_name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3"><x-badge :status="$document->visibility === 'staff' ? 'info' : 'neutral'" :label="ucfirst($document->visibility)" /></td>
                                <td class="px-4 py-3"><x-badge :status="$document->status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $document->created_at?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ route('documents.show', $document) }}" class="text-sm font-medium text-brand-700 hover:text-brand-800">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-4 py-3">{{ $documents->links() }}</div>
        @endif
    </x-card>
@endsection
