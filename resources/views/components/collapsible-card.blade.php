@props(['title', 'subtitle' => null, 'open' => false])

<details {{ $attributes->merge(['class' => 'group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm']) }} @if ($open) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50 [&::-webkit-details-marker]:hidden">
        <div>
            <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
            @if ($subtitle)
                <p class="text-xs text-slate-500">{{ $subtitle }}</p>
            @endif
        </div>
        <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-slate-500 transition-transform duration-200 group-open:rotate-180" />
    </summary>

    <div class="border-t border-slate-200 px-4 py-4">
        {{ $slot }}
    </div>
</details>
