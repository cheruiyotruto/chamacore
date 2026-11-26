@extends('layouts.platform')

@section('title', 'Currencies')

@section('content')
    <x-page-header title="Currencies"
        subtitle="Choose which currencies organisations in any country can select in their group settings." />

    <div class="mt-6">
        <x-card title="Available currencies"
            subtitle="Enabled currencies appear in every group's settings. Disabling one removes it from new selections but does not alter groups already using it.">
            <form method="POST" action="{{ route('platform.currencies.update') }}">
                @csrf
                @method('PUT')

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th class="w-12 px-3 py-2">Enabled</th>
                                <th class="px-3 py-2">Code</th>
                                <th class="px-3 py-2">Currency</th>
                                <th class="px-3 py-2">Symbol</th>
                                <th class="px-3 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($catalogue as $code => $definition)
                                @php($isEnabled = in_array($code, $enabled, true))
                                <tr class="{{ $isEnabled ? 'bg-white' : 'bg-slate-50' }}">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" name="currencies[]" value="{{ $code }}"
                                            id="currency-{{ $code }}" @checked($isEnabled)
                                            class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    </td>
                                    <td class="px-3 py-2 font-semibold text-slate-800">
                                        <label for="currency-{{ $code }}" class="cursor-pointer">{{ $code }}</label>
                                    </td>
                                    <td class="px-3 py-2 text-slate-700">{{ $definition['name'] }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $definition['symbol'] }}</td>
                                    <td class="px-3 py-2">
                                        @if ($isEnabled)
                                            <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Enabled</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600">Disabled</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @error('currencies')
                    <p class="mt-3 text-sm text-rose-600">{{ $message }}</p>
                @enderror
                @error('currencies.*')
                    <p class="mt-3 text-sm text-rose-600">Select only supported currencies.</p>
                @enderror

                <p class="mt-4 text-xs text-slate-500">
                    Only currencies with two decimal places are offered because amounts are stored in minor units
                    (100 per major unit). Enable at least one currency.
                </p>

                <div class="mt-4">
                    <button type="submit"
                        class="inline-flex items-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                        Save currencies
                    </button>
                </div>
            </form>
        </x-card>
    </div>
@endsection
