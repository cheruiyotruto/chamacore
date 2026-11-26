@php
    $cycle = $cycle ?? null;
    $frequency = old('frequency', $cycle?->frequency ?? 'monthly');
    $dayOfWeekOptions = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
    $contributionValue = old(
        'contribution_amount',
        $cycle ? number_format($cycle->contribution_amount / 100, 2, '.', '') : null
    );
@endphp

<div x-data="{ frequency: '{{ $frequency }}' }" class="space-y-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-input name="name" label="Cycle name" placeholder="e.g. 2026 rotation" :value="$cycle?->name" required />
        <x-input name="contribution_amount" type="number" step="0.01" min="1" label="Contribution per member" :value="$contributionValue" required help="Amount each member contributes every cycle." />
        <x-select name="frequency" label="Frequency" x-model="frequency" :options="['weekly' => 'Weekly', 'monthly' => 'Monthly']" :value="$cycle?->frequency" required />

        <div x-cloak x-show="frequency === 'weekly'">
            <x-select name="day_of_week" label="Collection day" placeholder="Same weekday as start date" :options="$dayOfWeekOptions" :value="$cycle?->day_of_week" help="Weekly cycles collect on this weekday." />
        </div>

        <div x-cloak x-show="frequency === 'monthly'">
            <x-input name="day_of_month" type="number" min="1" max="31" label="Collection day of month" :value="$cycle?->day_of_month" help="Monthly cycles collect on this day. Short months use their last day." />
        </div>

        <x-input name="start_date" type="date" label="Start date" :value="$cycle?->start_date ?? now()" required help="The first collection is the first chosen day on or after this date." />
        <x-input name="end_date" type="date" label="End date" :value="$cycle?->end_date ?? now()->addMonths(11)" required />
        <x-select name="rotation_type" label="Rotation order" :options="['fixed' => 'In the order selected', 'random' => 'Randomly shuffled']" :value="$cycle?->rotation_type" required />
        <x-input name="penalty_rate_bps" type="number" min="0" max="10000" label="Late penalty (bps)" :value="$cycle?->penalty_rate_bps" help="100 bps = 1% of the contribution" />
        <x-input name="penalty_grace_days" type="number" min="0" max="365" label="Grace days" :value="$cycle?->penalty_grace_days" help="Days after the due date before a penalty applies" />
        <x-input name="min_contributions_before_payout" type="number" min="0" max="100" label="Minimum contributions before payout" :value="$cycle?->min_contributions_before_payout ?? 1" />
    </div>

    <label class="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm text-slate-700">
        <input type="checkbox" name="payout_requires_contributions" value="1" @checked(old('payout_requires_contributions', $cycle?->payout_requires_contributions ?? true)) class="mt-0.5 rounded border-slate-300 text-brand-600">
        <span>
            Require contributions before a payout
            <span class="block text-xs text-slate-500">Members must meet the minimum contribution count before receiving the pool.</span>
        </span>
    </label>

    <x-textarea name="notes" label="Notes" :value="$cycle?->notes" help="Optional internal notes about this cycle." />
</div>
