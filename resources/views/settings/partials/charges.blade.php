@php
    $scopeLabels = [
        \App\Models\TransactionCharge::APPLIES_ALL => 'All members',
        \App\Models\TransactionCharge::APPLIES_CATEGORIES => 'Selected membership categories',
        \App\Models\TransactionCharge::APPLIES_MEMBERS => 'Selected members only',
    ];
@endphp

<x-card title="How transaction charges work"
        subtitle="Charges are configured once here, then applied automatically when transactions are posted at the teller desk.">
    <div class="grid gap-3 text-sm text-slate-600 sm:grid-cols-2">
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="font-semibold text-slate-800">Fixed or percentage</p>
            <p class="mt-1 text-xs">A charge can be a flat amount in {{ $group->currency }}, a percentage of the transaction, or both with a minimum and maximum cap.</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="font-semibold text-slate-800">Who pays</p>
            <p class="mt-1 text-xs">Target every member, selected membership categories (for example ordinary or associate), or hand-pick individual members.</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="font-semibold text-slate-800">Mandatory or waivable</p>
            <p class="mt-1 text-xs">Mandatory charges are always applied. Optional charges can be waived by the teller for a specific transaction.</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <p class="font-semibold text-slate-800">Income account</p>
            <p class="mt-1 text-xs">Each charge credits its own income account, so the ledger shows exactly which charges were collected.</p>
        </div>
    </div>

    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        Cash-in charges are collected on top of the transaction amount. Cash-out charges are deducted from the amount paid to the member.
        Disabled charges and charges outside a member's scope are never applied.
    </p>
</x-card>

@if ($canManage)
    <x-card title="Add a transaction charge" subtitle="Create a charge and choose exactly which transactions and members it applies to.">
        <form method="POST" action="{{ route('settings.charges.store') }}" class="space-y-5"
              x-data="{ scope: '{{ old('applies_to', \App\Models\TransactionCharge::APPLIES_ALL) }}', basis: '{{ old('basis', \App\Models\TransactionCharge::BASIS_FIXED) }}' }">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="name" label="Charge name" placeholder="e.g. Deposit handling fee" required />
                <x-select name="category" label="Transaction type" :options="$chargeCategories" required help="Choose one transaction type or apply to all." />
                <x-select name="income_account_code" label="Income account" :options="$incomeAccounts" required help="The ledger account credited when the charge is collected." />

                <div>
                    <label class="block text-sm font-medium text-slate-700">Charge basis</label>
                    <select name="basis" x-model="basis" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                        <option value="fixed">Fixed amount</option>
                        <option value="percent">Percentage of amount</option>
                    </select>
                </div>

                <div x-show="basis === 'fixed'">
                    <x-input name="amount" type="number" step="0.01" min="0" label="Fixed charge ({{ $group->currency }})" placeholder="0.00" />
                </div>

                <div x-show="basis === 'percent'" x-cloak>
                    <x-input name="rate" type="number" step="0.01" min="0" max="100" label="Rate (%)" placeholder="e.g. 1.5" help="1.5 means 1.5% of the transaction amount." />
                </div>

                <x-input name="min_amount" type="number" step="0.01" min="0" label="Minimum charge" placeholder="0.00" help="Optional floor for percentage charges." />
                <x-input name="max_amount" type="number" step="0.01" min="0" label="Maximum charge" placeholder="Leave blank for no cap" />
                <x-input name="sort_order" type="number" min="0" max="9999" label="Display order" placeholder="0" help="Lower numbers are shown first." />
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                    <input type="hidden" name="is_mandatory" value="0">
                    <input type="checkbox" name="is_mandatory" value="1" @checked(old('is_mandatory', true)) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-800">Mandatory charge</span>
                        <span class="block text-xs text-slate-500">The teller cannot waive this charge.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true)) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>
                        <span class="block text-sm font-medium text-slate-800">Enable this charge</span>
                        <span class="block text-xs text-slate-500">Uncheck to keep the rule but stop applying it.</span>
                    </span>
                </label>
            </div>

            <div>
                <x-select name="applies_to" label="Who pays this charge" :options="$scopeLabels" required x-model="scope" />

                <div x-show="scope === '{{ \App\Models\TransactionCharge::APPLIES_CATEGORIES }}'" x-cloak class="mt-4">
                    <p class="text-sm font-medium text-slate-700">Membership categories</p>
                    <p class="mt-0.5 text-xs text-slate-500">Only members in the ticked categories pay this charge.</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-3">
                        @foreach ($membershipCategories as $category)
                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700">
                                <input type="checkbox" name="member_categories[]" value="{{ $category }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                <span>{{ ucfirst($category) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div x-show="scope === '{{ \App\Models\TransactionCharge::APPLIES_MEMBERS }}'" x-cloak class="mt-4">
                    <p class="text-sm font-medium text-slate-700">Members</p>
                    <p class="mt-0.5 text-xs text-slate-500">Only the ticked members pay this charge.</p>
                    <div class="mt-2 grid max-h-72 gap-2 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-3">
                        @forelse ($members as $member)
                            <label class="flex items-start gap-2 rounded-lg border border-slate-100 p-2.5 text-sm hover:bg-slate-50">
                                <input type="checkbox" name="member_ids[]" value="{{ $member->id }}" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-slate-800">{{ $member->full_name }}</span>
                                    <span class="block truncate text-xs text-slate-500">{{ $member->member_no }} · {{ ucfirst($member->membership_category ?? 'ordinary') }}</span>
                                </span>
                            </label>
                        @empty
                            <p class="text-sm text-slate-500">There are no active members yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <x-textarea name="description" label="Internal description" rows="2" placeholder="Optional note explaining why this charge exists." />

            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Add charge
            </button>
        </form>
    </x-card>
@endif

<x-card title="Configured charges" :subtitle="$charges->count().' charge rule(s)'" :padding="false">
    @if ($charges->isEmpty())
        <x-empty title="No transaction charges yet"
                 message="Add a charge above to start collecting fees at the teller desk."
                 class="m-4" />
    @else
        <div class="divide-y divide-slate-100">
            @foreach ($charges as $charge)
                <details class="group">
                    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900">
                                {{ $charge->name }}
                                <x-badge :status="$charge->is_active ? 'active' : 'inactive'" :label="$charge->is_active ? 'Enabled' : 'Disabled'" />
                                <x-badge :status="$charge->is_mandatory ? 'pending' : 'submitted'" :label="$charge->is_mandatory ? 'Mandatory' : 'Optional'" />
                            </p>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ $charge->categoryLabel() }} · {{ $charge->summary() }} · {{ $charge->scopeLabel() }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-[11px] text-slate-400">GL {{ $charge->income_account_code }}</span>
                            <x-icon name="arrow-right" class="h-4 w-4 text-slate-400 transition group-open:rotate-90" />
                        </div>
                    </summary>

                    <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-4">
                        @if ($canManage)
                            <form method="POST" action="{{ route('settings.charges.update', $charge) }}" class="space-y-5"
                                  x-data="{ scope: '{{ $charge->applies_to }}', basis: '{{ $charge->basis }}' }">
                                @csrf
                                @method('PUT')

                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <x-input name="name" label="Charge name" :value="$charge->name" required />
                                    <x-select name="category" label="Transaction type" :options="$chargeCategories" :value="$charge->category" required />
                                    <x-select name="income_account_code" label="Income account" :options="$incomeAccounts" :value="$charge->income_account_code" required />

                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Charge basis</label>
                                        <select name="basis" x-model="basis" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                            <option value="fixed">Fixed amount</option>
                                            <option value="percent">Percentage of amount</option>
                                        </select>
                                    </div>

                                    <div x-show="basis === 'fixed'">
                                        <x-input name="amount" type="number" step="0.01" min="0" label="Fixed charge ({{ $group->currency }})" :value="$charge->amount / 100" />
                                    </div>

                                    <div x-show="basis === 'percent'" x-cloak>
                                        <x-input name="rate" type="number" step="0.01" min="0" max="100" label="Rate (%)" :value="$charge->rate_bps / 100" />
                                    </div>

                                    <x-input name="min_amount" type="number" step="0.01" min="0" label="Minimum charge" :value="$charge->min_amount / 100" />
                                    <x-input name="max_amount" type="number" step="0.01" min="0" label="Maximum charge" :value="$charge->max_amount !== null ? $charge->max_amount / 100 : null" />
                                    <x-input name="sort_order" type="number" min="0" max="9999" label="Display order" :value="$charge->sort_order" />
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-3">
                                        <input type="hidden" name="is_mandatory" value="0">
                                        <input type="checkbox" name="is_mandatory" value="1" @checked($charge->is_mandatory) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span>
                                            <span class="block text-sm font-medium text-slate-800">Mandatory charge</span>
                                            <span class="block text-xs text-slate-500">The teller cannot waive this charge.</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 bg-white p-3">
                                        <input type="hidden" name="is_active" value="0">
                                        <input type="checkbox" name="is_active" value="1" @checked($charge->is_active) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                        <span>
                                            <span class="block text-sm font-medium text-slate-800">Enable this charge</span>
                                            <span class="block text-xs text-slate-500">Uncheck to keep the rule but stop applying it.</span>
                                        </span>
                                    </label>
                                </div>

                                <div>
                                    <x-select name="applies_to" label="Who pays this charge" :options="$scopeLabels" :value="$charge->applies_to" required x-model="scope" />

                                    <div x-show="scope === '{{ \App\Models\TransactionCharge::APPLIES_CATEGORIES }}'" x-cloak class="mt-4">
                                        <p class="text-sm font-medium text-slate-700">Membership categories</p>
                                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                            @foreach ($membershipCategories as $category)
                                                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                                    <input type="checkbox" name="member_categories[]" value="{{ $category }}" @checked(in_array($category, $charge->member_categories ?? [], true)) class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                    <span>{{ ucfirst($category) }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div x-show="scope === '{{ \App\Models\TransactionCharge::APPLIES_MEMBERS }}'" x-cloak class="mt-4">
                                        <p class="text-sm font-medium text-slate-700">Members</p>
                                        <div class="mt-2 grid max-h-72 gap-2 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-3">
                                            @foreach ($members as $member)
                                                <label class="flex items-start gap-2 rounded-lg border border-slate-100 p-2.5 text-sm hover:bg-slate-50">
                                                    <input type="checkbox" name="member_ids[]" value="{{ $member->id }}" @checked($charge->members->contains('id', $member->id)) class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                                    <span class="min-w-0">
                                                        <span class="block truncate font-medium text-slate-800">{{ $member->full_name }}</span>
                                                        <span class="block truncate text-xs text-slate-500">{{ $member->member_no }} · {{ ucfirst($member->membership_category ?? 'ordinary') }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <x-textarea name="description" label="Internal description" rows="2" :value="$charge->description" />

                                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Save charge</button>
                            </form>

                            <form method="POST" action="{{ route('settings.charges.destroy', $charge) }}" class="mt-4 border-t border-slate-200 pt-4"
                                  onsubmit="return confirm('Delete this charge? This cannot be undone.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-white px-3.5 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">
                                    <x-icon name="x" class="h-4 w-4" /> Delete charge
                                </button>
                            </form>
                        @else
                            <p class="text-sm text-slate-500">Only an administrator with settings permission can change this charge.</p>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</x-card>
