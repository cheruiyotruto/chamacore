@props(['label', 'value', 'hint' => null, 'tone' => 'brand'])

@php
    $accent = match ($tone) {
        'danger' => 'bg-rose-500',
        'warning' => 'bg-amber-500',
        'info' => 'bg-sky-500',
        'neutral' => 'bg-slate-400',
        default => 'bg-brand-500',
    };
@endphp

<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-xl border border-slate-200 bg-white p-4 shadow-sm']) }}>
    <span class="absolute inset-y-0 left-0 w-1 {{ $accent }}"></span>
    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
    <p class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
