@props(['title', 'subtitle' => null])

@php
    $headerActions = trim($actions ?? '') !== '' ? $actions : $slot;
@endphp

<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>
    @if (trim($headerActions ?? '') !== '')
        <div class="flex flex-wrap items-center gap-2">{{ $headerActions }}</div>
    @endif
</div>
