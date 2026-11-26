@php
    $rule = $rule ?? null;
    $selectedChannels = old('channels', $rule?->channels ?? ['in_app', 'sms']);
    $selectedMembers = array_map('intval', old('member_ids', $rule?->member_ids ?? []));
    $type = old('type', $rule?->type ?? \App\Models\ReminderRule::TYPE_MGR_CONTRIBUTION);
@endphp

<form method="POST" action="{{ $action }}" class="space-y-3">
    @csrf
    @if (($method ?? 'POST') !== 'POST')
        @method($method)
    @endif

    <x-input name="name" label="Name" :value="old('name', $rule?->name)" placeholder="e.g. Loan repayment - 7 days before" required />

    <x-select name="type" label="Reminder for" :options="$ruleTypes" :value="$type" required />

    <div class="grid gap-3 sm:grid-cols-2">
        <x-input name="offset_days" type="number" label="Days before/after date"
            :value="old('offset_days', $rule?->offset_days ?? -3)"
            help="Use -3 for three days before, 0 on the date, 2 for two days after." />
        <x-input name="specific_date" type="date" label="Specific date (custom announcements)"
            :value="old('specific_date', $rule?->specific_date?->toDateString())" />
    </div>

    <x-select name="audience" label="Audience" :options="$audiences" :value="old('audience', $rule?->audience ?? 'auto')" required />

    <details class="rounded-lg border border-slate-200 p-3">
        <summary class="cursor-pointer text-sm font-medium text-slate-700">Limit to selected members</summary>
        <div class="mt-2 max-h-40 space-y-1 overflow-y-auto">
            @forelse ($members as $memberOption)
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="member_ids[]" value="{{ $memberOption->id }}"
                        @checked(in_array($memberOption->id, $selectedMembers, true))
                        class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>{{ $memberOption->full_name }}</span>
                </label>
            @empty
                <p class="text-xs text-slate-500">No active members.</p>
            @endforelse
        </div>
    </details>

    <x-select name="template_key" label="Message template" :options="$templateKeys"
        :value="old('template_key', $rule?->template_key ?? 'admin_reminder')" required />

    <x-input name="subject" label="Title override" :value="old('subject', $rule?->subject)" placeholder="Leave blank to use the template title" />
    <x-textarea name="body" label="Message override" rows="3" :value="old('body', $rule?->body)" placeholder="Leave blank to use the template message" />

    <div>
        <p class="mb-1 text-sm font-medium text-slate-700">Channels</p>
        <div class="flex flex-wrap gap-3">
            @foreach (['in_app' => 'In app', 'sms' => 'SMS', 'email' => 'Email', 'push' => 'Push'] as $channel => $label)
                <label class="flex items-center gap-1.5 text-xs text-slate-600">
                    <input type="checkbox" name="channels[]" value="{{ $channel }}"
                        @checked(in_array($channel, $selectedChannels, true))
                        class="h-3.5 w-3.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    {{ $label }}
                </label>
            @endforeach
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule?->is_active ?? true))
            class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
        Enabled
    </label>

    <button class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-700">
        {{ $submitLabel }}
    </button>
</form>
