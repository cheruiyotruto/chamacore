@props(['charges' => collect(), 'direction' => 'in'])

@php
    $optional = $charges->contains(fn ($charge) => ! $charge->is_mandatory);
@endphp

@if ($charges->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'sm:col-span-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900']) }}>
        <p class="font-semibold uppercase tracking-wide">Charges that will apply</p>
        <ul class="mt-1 space-y-0.5">
            @foreach ($charges as $charge)
                <li>
                    <span class="font-medium">{{ $charge->name }}</span> · {{ $charge->summary() }}
                    <span class="font-semibold">{{ $charge->is_mandatory ? '(mandatory)' : '(optional)' }}</span>
                </li>
            @endforeach
        </ul>

        <p class="mt-1 text-[11px] text-amber-800">
            {{ $direction === 'out' ? 'Charges are deducted from the amount paid out.' : 'Charges are collected in addition to the amount received.' }}
        </p>

        @if ($optional)
            <label class="mt-2 flex items-center gap-2">
                <input type="hidden" name="waive_charges" value="0">
                <input type="checkbox" name="waive_charges" value="1" class="h-3.5 w-3.5 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                <span>Waive optional charges</span>
            </label>
        @endif
    </div>
@endif
