@php
    $money = $money ?? fn (?int $minor) => $minor === null || $minor === '' ? '' : number_format($minor / 100, 2, '.', '');
@endphp

<div class="space-y-4">
    @foreach ($categories as $key => $label)
        @php $rule = $rules[$key] ?? []; @endphp
        <div class="rounded-lg border border-slate-200 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-slate-900">{{ $label }}</p>
                    <p class="text-xs text-slate-500">
                        @if (isset($inherited) && $inherited === false)
                            Platform default shown below can be overridden for this group.
                        @else
                        @endif
                    </p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="hidden" name="rules[{{ $key }}][enabled]" value="0">
                    <input type="checkbox" name="rules[{{ $key }}][enabled]" value="1"
                        @checked(($rule['enabled'] ?? false)) @disabled(! $canManage)
                        class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Enable charge
                </label>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-select name="rules[{{ $key }}][mode]" label="Charge formula" :options="$modes"
                    :value="$rule['mode'] ?? 'percent'" :disabled="! $canManage" />

                <x-input name="rules[{{ $key }}][rate_bps]" type="number" min="0" max="10000"
                    label="Percentage (bps)" :value="$rule['rate_bps'] ?? 0"
                    help="100 bps = 1%. Used by percentage formulas." :disabled="! $canManage" />

                <x-input name="rules[{{ $key }}][amount]" type="number" step="0.01" min="0"
                    label="Fixed amount" :value="$money($rule['amount'] ?? 0)"
                    help="Used by fixed and fixed + percentage formulas." :disabled="! $canManage" />

                <x-input name="rules[{{ $key }}][min_amount]" type="number" step="0.01" min="0"
                    label="Minimum charge" :value="$money($rule['min_amount'] ?? 0)" :disabled="! $canManage" />

                <x-input name="rules[{{ $key }}][max_amount]" type="number" step="0.01" min="0"
                    label="Maximum charge" :value="$money($rule['max_amount'] ?? 0)"
                    help="0 means no cap." :disabled="! $canManage" />

                <x-select name="rules[{{ $key }}][borne_by]" label="Who bears the charge" :options="$borneBy"
                    :value="$rule['borne_by'] ?? 'payer'" :disabled="! $canManage" />

                <x-select name="rules[{{ $key }}][income_account_code]" label="Income account" :options="$incomeAccounts"
                    :value="$rule['income_account_code'] ?? '4060'" :disabled="! $canManage" />
            </div>
        </div>
    @endforeach

    @if ($canManage)
        <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            Save payment charges
        </button>
    @endif
</div>
