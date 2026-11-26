@extends('layouts.app')

@php
    $val = fn (string $key, mixed $default = null) => old($key, $values[$key] ?? $default);
    $editing = $product?->exists ?? false;
    $title = $editing ? 'Edit '.$product->name : 'New loan product';
    $templateOptions = [
        'salary_advance' => 'Salary advance',
        'business' => 'Business loan',
        'emergency' => 'Emergency loan',
        'education' => 'Education loan',
        'agriculture' => 'Agriculture loan',
        'personal' => 'Personal loan',
        'asset' => 'Asset finance',
    ];
@endphp

@section('title', $title)

@section('content')
    <x-page-header :title="$title" subtitle="Every requirement below is stored per loan type, so members only see and qualify against the rules you choose.">
        <a href="{{ route('loan-products.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> All products
        </a>
    </x-page-header>

    @unless ($editing)
        <x-card title="Start from a product type" subtitle="Pick a type to pre-fill its usual requirements. You can then adjust every value for this product.">
            <form method="GET" action="{{ route('loan-products.create') }}" class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-72">
                    <x-select name="template" label="Product type template" :options="$templateOptions" :value="$template" placeholder="Choose a type" />
                </div>
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    <x-icon name="check" class="h-4 w-4" /> Load requirements
                </button>
            </form>
        </x-card>
    @endunless

    <form method="POST" action="{{ $editing ? route('loan-products.update', $product) : route('loan-products.store') }}" class="space-y-5">
        @csrf
        @if ($editing)
            @method('PUT')
        @endif

        <x-card title="Product identity" subtitle="The type tells members what the loan is for; the code identifies it in reports.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" x-data="{ category: @js((string) $val('category')) }">
                <x-input name="name" label="Product name" :value="$val('name')" required placeholder="e.g. Emergency loan" />
                <x-input name="code" label="Product code" :value="$val('code')" required placeholder="e.g. EMERGENCY" help="Letters, numbers, dashes and underscores only." />
                <x-select name="category" label="Product type" :options="$categories" :value="$val('category')" required x-model="category" />
                <div x-cloak x-show="category === '{{ \App\Models\LoanProduct::CUSTOM_CATEGORY }}'" class="sm:col-span-2 lg:col-span-3">
                    <x-input name="custom_category" label="Custom loan type" :value="$val('custom_category')" placeholder="e.g. School fees, Salary advance, Stock purchase" help="Describe the type of loan members need. It is shown to members and used in reports." />
                </div>
            </div>

            <div class="mt-4">
                <x-textarea name="description" label="Description" rows="2" :value="$val('description')" placeholder="Who is this loan for and what can it be used for?" />
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm text-slate-700">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked($val('is_active')) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                Make this product available for new applications
            </label>
        </x-card>

        <x-card title="Interest and calculation" subtitle="How the interest is calculated, quoted and charged.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-select name="interest_method" label="Interest method" :options="$methods" :value="$val('interest_method')" required />
                <x-input name="interest_rate" type="number" step="0.01" min="0" max="100" label="Interest rate (% per period)" :value="$val('interest_rate')" required />
                <x-select name="interest_period" label="Interest period" :options="$interestPeriods" :value="$val('interest_period')" required />
                <x-select name="accrual_mode" label="Accrual mode" :options="$accrualModes" :value="$val('accrual_mode')" required help="Effective compounds the quoted rate; simple charges it pro rata." />
                <x-select name="day_count" label="Day-count convention" :options="$dayCounts" :value="$val('day_count')" required />
                <x-select name="repayment_frequency" label="Repayment frequency" :options="$frequencies" :value="$val('repayment_frequency')" required />
                <x-input name="repayment_day" type="number" min="1" max="31" label="Preferred repayment day" :value="$val('repayment_day')" help="Optional. Leave blank to use the application date." />
            </div>
        </x-card>

        <x-card title="Amount and term limits" subtitle="These limits are enforced when a member requests this product.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="min_amount" type="number" step="0.01" min="0" label="Minimum amount" :value="$val('min_amount')" required />
                <x-input name="max_amount" type="number" step="0.01" min="0" label="Maximum amount" :value="$val('max_amount')" required />
                <div class="grid grid-cols-3 gap-3">
                    <x-input name="duration_min" type="number" min="1" max="600" label="Min term" :value="$val('duration_min')" required />
                    <x-input name="duration_max" type="number" min="1" max="600" label="Max term" :value="$val('duration_max')" required />
                    <x-select name="duration_unit" label="Unit" :options="$durationUnits" :value="$val('duration_unit')" required />
                </div>
            </div>
        </x-card>

        <x-card title="Eligibility and borrowing limit" subtitle="How much a member can borrow and which savings count towards their limit.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="multiplier" type="number" step="0.01" min="0" max="1000" label="Borrowing multiplier (%)" :value="$val('multiplier')" help="300% means a member may borrow up to 3x their qualifying savings. Set to 0 for advances/loans that do not use a savings multiplier." />
                <x-input name="guarantee_multiplier" type="number" step="0.01" min="0" max="1000" label="Guarantee capacity multiplier (%)" :value="$val('guarantee_multiplier')" help="Limits how much a member can guarantee for others. Set to 0 if not used." />
                <x-input name="min_membership_days" type="number" min="0" max="3650" label="Minimum membership (days)" :value="$val('min_membership_days')" required />
                <x-input name="max_concurrent_loans" type="number" min="0" max="20" label="Maximum concurrent loans" :value="$val('max_concurrent_loans')" required />
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="mt-1 flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="requires_no_arrears" value="0">
                        <input type="checkbox" name="requires_no_arrears" value="1" @checked($val('requires_no_arrears')) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        Member must have no arrears on existing loans
                    </label>
                </div>
            </div>

            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <label for="qualifying_savings_product_ids" class="block text-sm font-medium text-slate-700">Qualifying savings products</label>
                    <select id="qualifying_savings_product_ids" name="qualifying_savings_product_ids[]" multiple size="5"
                            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                        @php
                            $selectedSavings = array_map('intval', (array) old('qualifying_savings_product_ids', $values['qualifying_savings_product_ids'] ?? []));
                        @endphp
                        @foreach ($savingsProducts as $savingsProduct)
                            <option value="{{ $savingsProduct->id }}" @selected(in_array($savingsProduct->id, $selectedSavings, true))>
                                {{ $savingsProduct->name }} ({{ $savingsProduct->code }}) Â· {{ ucfirst($savingsProduct->type) }}{{ $savingsProduct->qualifies_for_loan ? ' Â· qualifies' : ' Â· does not qualify' }}
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Select one or more products. Leave empty to use every savings product marked as qualifying for loans.</p>
                    @error('qualifying_savings_product_ids')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
                <div class="space-y-4">
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="hidden" name="include_shares" value="0">
                        <input type="checkbox" name="include_shares" value="1" @checked($val('include_shares')) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        Include share capital
                    </label>
                    <x-input name="share_weight" type="number" step="0.01" min="0" max="100" label="Share weight (%)" :value="$val('share_weight')" help="Portion of share capital counted towards the limit." />
                </div>
            </div>
        </x-card>

        <x-card title="Guarantor requirements" subtitle="Choose whether this loan uses guarantors, and how much cover they must provide when used.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-select name="guarantor_mode" label="Guarantor mode" :options="$guarantorModes" :value="$val('guarantor_mode')" required help="Optional allows loans with or without guarantors. Not used gives multiplier-based loans without guarantors." />
                <x-input name="min_guarantors" type="number" min="0" max="20" label="Minimum guarantors" :value="$val('min_guarantors')" required />
                <x-input name="guarantor_coverage" type="number" step="0.01" min="0" max="100" label="Required coverage (%)" :value="$val('guarantor_coverage')" required help="100% means guarantors must cover the full loan amount when guarantors are used." />
            </div>
        </x-card>

        <x-card title="Advance / employer remittance" subtitle="For salary advances and other loans repaid through an employer, require payslips, employment letter, online signature and a stamped employer remittance form.">
            <label class="flex items-start gap-2 text-sm text-slate-700">
                <input type="hidden" name="requires_employer_remittance" value="0">
                <input type="checkbox" name="requires_employer_remittance" value="1" @checked($val('requires_employer_remittance')) class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>
                    Require employer remittance / advance-loan documents
                    <span class="mt-0.5 block text-xs text-slate-500">Applicants must attach payslips and an employment letter, sign online, and upload a completed employer finance remittance form (officer details, signature, date, stamp, remit-to/paybill) before disbursement.</span>
                </span>
            </label>
        </x-card>

        <x-card title="Fees, penalties and repayment allocation" subtitle="Charges applied to this product and the order repayments are allocated.">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-input name="processing_fee" type="number" step="0.01" min="0" max="100" label="Processing fee (%)" :value="$val('processing_fee')" required />
                <x-input name="insurance_fee" type="number" step="0.01" min="0" max="100" label="Insurance fee (%)" :value="$val('insurance_fee')" required />
                <x-input name="penalty_rate" type="number" step="0.01" min="0" max="100" label="Late penalty rate (%)" :value="$val('penalty_rate')" required />
                <x-select name="penalty_period" label="Penalty period" :options="$penaltyPeriods" :value="$val('penalty_period')" required />
                <x-input name="penalty_grace_days" type="number" min="0" max="365" label="Penalty grace days" :value="$val('penalty_grace_days')" required />
                <x-select name="allocation_policy" label="Repayment allocation order" :options="$allocationPolicies" :value="$val('allocation_policy')" required />
            </div>
        </x-card>

        <x-card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-500">
                    {{ $editing ? 'Changes apply to new applications only; existing loans keep their original terms.' : 'The product becomes available to members as soon as it is active.' }}
                </p>
                <div class="flex items-center gap-2">
                    <a href="{{ route('loan-products.index') }}" class="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700">Cancel</a>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="check" class="h-4 w-4" /> {{ $editing ? 'Save changes' : 'Create loan product' }}
                    </button>
                </div>
            </div>
        </x-card>
    </form>
@endsection
