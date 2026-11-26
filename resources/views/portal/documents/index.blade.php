@extends('layouts.portal')

@section('title', 'My documents')

@section('content')
    @php
        $typeLabels = config('chama.document_types', []);
        $verified = $documents->getCollection()->where('status', 'verified')->count();
        $pending = $documents->getCollection()->where('status', 'pending')->count();
    @endphp

    <div class="mb-6">
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">My documents</h1>
        <p class="mt-1 text-sm text-slate-500">Statements, agreements and other records the group has shared with you.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Documents" :value="number_format($documents->total())" hint="Available to you" />
        <x-stat label="Verified" :value="number_format($verified)" hint="Verified on this page" tone="brand" />
        <x-stat label="Pending review" :value="number_format($pending)" hint="Awaiting verification" tone="warning" />
    </div>

    <div class="mt-6">
        <x-card title="Document library" :subtitle="$documents->total().' document(s)'" :padding="false">
            @if ($documents->isEmpty())
                <x-empty title="No documents yet" message="Documents shared by the group office will appear here." class="m-4" />
            @else
                <div class="divide-y divide-slate-100">
                    @foreach ($documents as $document)
                        <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-4">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                    <x-icon name="folder" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-slate-900">{{ $document->title }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $typeLabels[$document->type] ?? ucfirst(str_replace('_', ' ', $document->type)) }}
                                        · {{ $document->mime_type }}
                                        @if ($document->size)
                                            · {{ number_format($document->size / 1024, 1) }} KB
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        Added {{ $document->created_at?->format('d M Y') ?? '—' }}
                                        @if ($document->verified_at)
                                            · Verified {{ $document->verified_at->format('d M Y') }}
                                        @endif
                                        @if ($document->expires_at)
                                            · Expires {{ $document->expires_at->format('d M Y') }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-badge :status="$document->status" />
                                <a href="{{ route('portal.documents.download', $document) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    <x-icon name="download" class="h-3.5 w-3.5" /> Download
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="border-t border-slate-200 px-4 py-3">{{ $documents->links() }}</div>
            @endif
        </x-card>
    </div>
@endsection
