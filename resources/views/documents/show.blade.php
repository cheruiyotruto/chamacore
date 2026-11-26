@extends('layouts.app')

@section('title', $document->title)

@section('content')
    @php
        $ctx = $chamaContext ?? app(\App\Support\GroupContext::class);
        $can = fn (string $permission) => $ctx->hasPermission($permission);
        $expired = $document->expires_at && $document->expires_at->isPast();
        $logs = $document->accessLogs->sortByDesc('created_at')->take(12);
    @endphp

    <x-page-header :title="$document->title" :subtitle="ucfirst(str_replace('_', ' ', $document->type)).' · uploaded '.($document->created_at?->format('d M Y H:i') ?? '—')">
        <a href="{{ route('documents.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All documents
        </a>
        <a href="{{ route('documents.download', $document) }}" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            <x-icon name="download" class="h-4 w-4" /> Download
        </a>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Status" :value="ucfirst($document->status)" :hint="$document->verified_at ? 'Verified '.$document->verified_at->format('d M Y') : 'Awaiting review'" :tone="$document->isVerified() ? 'brand' : ($document->status === 'rejected' ? 'danger' : 'warning')" />
        <x-stat label="Visibility" :value="ucfirst($document->visibility)" hint="Who can access this file" tone="info" />
        <x-stat label="Size" :value="$document->size ? number_format($document->size / 1024, 1).' KB' : '—'" :hint="$document->mime_type ?: 'Unknown type'" tone="neutral" />
        <x-stat label="Expiry" :value="$document->expires_at?->format('d M Y') ?? 'No expiry'" :hint="$expired ? 'Expired' : 'Within validity'" :tone="$expired ? 'danger' : 'neutral'" />
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Document details">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Title</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $document->title }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Type</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ ucfirst(str_replace('_', ' ', $document->type)) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Member</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">
                            @if ($document->member)
                                <a href="{{ route('members.show', $document->member) }}" class="text-brand-700 hover:text-brand-800">{{ $document->member->full_name }}</a>
                            @else
                                Not member-specific
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Visibility</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ ucfirst($document->visibility) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Uploaded by</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $document->uploader?->name ?? 'System' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Verified by</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ $document->verifier?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Disk / path</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-slate-600">{{ $document->disk }}:{{ $document->path }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Reference</dt>
                        <dd class="mt-0.5 break-all font-mono text-xs text-slate-600">{{ $document->uuid }}</dd>
                    </div>
                </dl>

                @if ($document->notes)
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Notes</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $document->notes }}</p>
                    </div>
                @endif
            </x-card>

            @can('verify', $document)
                @if ($document->status !== 'verified')
                    <x-card title="Verification" subtitle="Confirm the document matches the member's records.">
                        <div class="flex flex-wrap items-center gap-3">
                            <form method="POST" action="{{ route('documents.verify', $document) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                                    <x-icon name="check" class="h-4 w-4" /> Verify document
                                </button>
                            </form>
                            <details class="w-full">
                                <summary class="cursor-pointer list-none text-sm font-semibold text-rose-700 hover:text-rose-800">Reject instead</summary>
                                <form method="POST" action="{{ route('documents.reject', $document) }}" class="mt-2 space-y-3 rounded-lg border border-rose-200 bg-rose-50 p-3">
                                    @csrf
                                    <x-textarea name="reason" label="Reason for rejection" rows="2" required />
                                    <button type="submit" class="rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100">Reject document</button>
                                </form>
                            </details>
                        </div>
                    </x-card>
                @endif
            @endcan
        </div>

        <div class="space-y-5">
            <x-card title="Access log" :subtitle="$logs->count().' recent event(s)'" :padding="false">
                @if ($logs->isEmpty())
                    <x-empty title="No access yet" message="Downloads and views are recorded here." class="m-4" />
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($logs as $log)
                            <div class="px-4 py-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-800">{{ ucfirst($log->action) }}</p>
                                    <span class="text-xs text-slate-400">{{ $log->created_at?->format('d M H:i') ?? '—' }}</span>
                                </div>
                                <p class="text-xs text-slate-500">{{ $log->user?->name ?? 'Unknown user' }}{{ $log->ip_address ? ' · '.$log->ip_address : '' }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>

            @can('delete', $document)
                <x-card title="Danger zone" subtitle="Deleting a document soft-deletes the record and keeps the access log.">
                    <form method="POST" action="{{ route('documents.destroy', $document) }}" onsubmit="return confirm('Delete this document?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                            <x-icon name="trash" class="h-4 w-4" /> Delete document
                        </button>
                    </form>
                </x-card>
            @endcan
        </div>
    </div>
@endsection
