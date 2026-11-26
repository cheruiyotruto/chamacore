@extends('layouts.portal')

@section('title', 'Apply for a loan')

@section('content')
    @php
        $applicant = $chamaContext?->membership() ?? null;
        $memberOptions = $members->mapWithKeys(fn ($member) => [$member->id => $member->member_no.' · '.$member->full_name])->all();
        $productOptions = $products->mapWithKeys(fn ($product) => [$product->id => $product->name.' ('.$product->code.')'])->all();
        $productMeta = $products->mapWithKeys(fn ($product) => [$product->id => [
            'name' => $product->name,
            'category' => $product->categoryLabel(),
            'description' => $product->description,
            'min_amount' => $product->min_amount,
            'max_amount' => $product->max_amount,
            'duration_min' => $product->duration_min,
            'duration_max' => $product->duration_max,
            'duration_unit' => $product->duration_unit,
            'repayment_frequency' => $product->repayment_frequency,
            'guarantor_mode' => $product->guarantorMode(),
            'requires_guarantors' => $product->guarantorsRequired(),
            'requires_employer_remittance' => $product->requiresEmployerRemittance(),
            'min_guarantors' => $product->min_guarantors,
            'guarantor_coverage_bps' => $product->guarantor_coverage_bps,
            'rate_bps' => $product->interest_rate_bps,
            'interest_period' => $product->interest_period,
            'method' => $product->methodLabel(),
            'multiplier_bps' => $product->multiplier_bps,
            'processing_fee_bps' => $product->processing_fee_bps,
            'insurance_fee_bps' => $product->insurance_fee_bps,
            'penalty_rate_bps' => $product->penalty_rate_bps,
            'penalty_period' => $product->penalty_period,
            'penalty_grace_days' => $product->penalty_grace_days,
            'min_membership_days' => $product->min_membership_days,
            'max_concurrent_loans' => $product->max_concurrent_loans,
            'requires_no_arrears' => (bool) $product->requires_no_arrears,
        ]])->all();
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">Apply for a loan</h1>
            <p class="mt-1 text-sm text-slate-500">Preview the full repayment schedule before you submit your application.</p>
        </div>
        <a href="{{ route('portal.loans.index') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            <x-icon name="arrow-left" class="h-4 w-4" /> My loans
        </a>
    </div>

    @if ($products->isEmpty())
        <x-empty title="No loan products available" message="The group has not published a loan product yet. Please contact the office." />
    @else
        <form method="POST" action="{{ route('portal.loans.store') }}" enctype="multipart/form-data" class="grid gap-5 xl:grid-cols-3"
              x-data="portalLoanApplication({ productMeta: @js($productMeta), memberOptions: @js($memberOptions), quoteUrl: '{{ route('portal.loans.quote') }}', memberRequiresEmployerFinance: @js((bool) $requiresEmployerFinance) })">
            @csrf

            <div class="space-y-5 xl:col-span-2">
                <x-card title="Application details" subtitle="All amounts are in Kenyan shillings.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-select name="loan_product_id" label="Loan product" :options="$productOptions" placeholder="Select a product" required x-model="productId" />
                        <div class="grid grid-cols-2 gap-3">
                            <x-input name="amount" type="number" step="0.01" min="0.01" label="Amount requested" required x-model="amount" />
                            <div class="grid grid-cols-2 gap-2">
                                <x-input name="duration" type="number" min="1" max="600" label="Duration" required x-model="duration" />
                                <x-select name="duration_unit" label="Unit" :options="['days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months']" x-model="durationUnit" />
                            </div>
                        </div>
                        <x-input name="purpose" label="Purpose" placeholder="e.g. School fees, stock" />
                        <div class="flex items-end">
                            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500" x-cloak x-show="product">
                                <span class="font-semibold text-slate-700" x-text="product?.method"></span>
                                <span x-text="product?.rate_bps ? ' · ' + (product.rate_bps / 100).toFixed(2) + '% per period' : ''"></span>
                                <span x-text="product ? ' · Limit ' + money(product.min_amount) + ' – ' + money(product.max_amount) : ''"></span>
                            </p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <x-textarea name="purpose_description" label="Supporting details" rows="3" placeholder="Any information the approving committee should know" />
                    </div>
                </x-card>

                <x-card title="Guarantors" subtitle="Members accept in their portal. People who are not members yet receive a join invitation.">
                    <template x-for="(guarantor, index) in guarantors" :key="index">
                        <div class="mb-3 flex items-end gap-3 rounded-lg border border-slate-200 p-3">
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-slate-700">Member</label>
                                <select name="guarantors[]" x-model="guarantor.memberId" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                    <option value="">Select a guarantor</option>
                                    <template x-for="(label, id) in memberOptions" :key="id">
                                        <option :value="id" x-text="label"></option>
                                    </template>
                                </select>
                            </div>
                            <button type="button" @click="guarantors.splice(index, 1)" class="rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">Remove</button>
                        </div>
                    </template>

                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <p class="mb-2 text-sm font-medium text-slate-800">Not a member yet</p>
                        <p class="mb-3 text-xs text-slate-500">We will send a join link. After approval, they deposit savings to fund the guarantee before it reaches them.</p>

                        <template x-for="(person, index) in nonMemberGuarantors" :key="'non-'+index">
                            <div class="mb-3 rounded-lg border border-dashed border-slate-300 p-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Email address</label>
                                        <input type="email" x-bind:name="'non_member_guarantors[' + index + '][email]'" placeholder="name@example.com" x-model="person.email" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700">Phone number</label>
                                        <input type="tel" x-bind:name="'non_member_guarantors[' + index + '][phone]'" placeholder="07..." x-model="person.phone" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
                                    </div>
                                </div>
                                <div class="mt-2 flex flex-wrap items-center gap-4 text-xs text-slate-600">
                                    <label class="inline-flex items-center gap-1.5">
                                        <input type="checkbox" value="email" x-model="person.channels" x-bind:name="'non_member_guarantors[' + index + '][channels][]'" class="rounded border-slate-300 text-brand-600">
                                        Email
                                    </label>
                                    <label class="inline-flex items-center gap-1.5">
                                        <input type="checkbox" value="sms" x-model="person.channels" x-bind:name="'non_member_guarantors[' + index + '][channels][]'" class="rounded border-slate-300 text-brand-600">
                                        SMS
                                    </label>
                                    <button type="button" @click="nonMemberGuarantors.splice(index, 1)" class="ml-auto text-xs font-semibold text-rose-600 hover:text-rose-700">Remove</button>
                                </div>
                            </div>
                        </template>

                        <button type="button" @click="nonMemberGuarantors.push({ email: '', phone: '', channels: [] })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <x-icon name="plus" class="h-4 w-4" /> Add non-member guarantor
                        </button>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <button type="button" @click="guarantors.push({ memberId: '' })" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <x-icon name="plus" class="h-4 w-4" /> Add member guarantor
                        </button>
                        <p class="text-xs text-slate-500" x-show="guarantors.length === 0 && nonMemberGuarantors.length === 0">No guarantors added.</p>
                    </div>

                    <p x-cloak x-show="product && product.guarantor_mode === 'required'" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        This product requires at least <span class="font-semibold" x-text="product?.min_guarantors"></span> guarantor(s).
                    </p>
                    <p x-cloak x-show="product && product.guarantor_mode === 'optional'" class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700">
                        Guarantors are optional for this product. You may apply with or without guarantors.
                    </p>
                    <p x-cloak x-show="product && product.guarantor_mode === 'none'" class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700">
                        This product is based on savings multiplier only and does not use guarantors.
                    </p>
                </x-card>

                <template x-if="needsEmployerFinance()">
                    <x-card title="Advance / employer remittance requirements"
                            subtitle="Provide applicant employment, salary and disbursement details, attach payslips and an employment letter, and sign online.">
                        <div class="space-y-5">
                            <div>
                                <p class="text-sm font-semibold text-slate-800">Employment & salary details</p>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <x-input name="advance_application[kra_pin]" label="KRA PIN" :value="old('advance_application.kra_pin', data_get($applicant?->metadata ?? [], 'kra_pin'))" />
                                    <x-input name="advance_application[payroll_number]" label="Payroll / staff number" :value="old('advance_application.payroll_number', $applicant?->member_no)" />
                                    <x-input name="advance_application[department]" label="Department / station" :value="old('advance_application.department')" />
                                    <x-input name="advance_application[job_title]" label="Job title / designation" :value="old('advance_application.job_title', $applicant?->occupation)" />
                                    <x-select name="advance_application[employment_terms]" label="Employment terms" :options="['' => 'Select', 'permanent' => 'Permanent', 'contract' => 'Contract', 'casual' => 'Casual', 'other' => 'Other']" :value="old('advance_application.employment_terms')" />
                                    <x-input name="advance_application[contract_expiry_date]" type="date" label="Contract expiry (if applicable)" :value="old('advance_application.contract_expiry_date')" />
                                    <x-input name="advance_application[gross_monthly_salary]" type="number" step="0.01" min="0" label="Gross monthly salary (KES)" :value="old('advance_application.gross_monthly_salary')" />
                                    <x-input name="advance_application[net_monthly_salary]" type="number" step="0.01" min="0" label="Net monthly salary (KES)" :value="old('advance_application.net_monthly_salary')" />
                                </div>
                            </div>

                            <div class="border-t border-slate-100 pt-4">
                                <p class="text-sm font-semibold text-slate-800">Disbursement details</p>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <x-select name="advance_application[disbursement_method]" label="Preferred disbursement method" :options="['' => 'Select', 'bank' => 'Bank transfer', 'fosa' => 'FOSA account', 'mobile_money' => 'Mobile money', 'cash' => 'Cash', 'other' => 'Other']" :value="old('advance_application.disbursement_method')" />
                                    <x-input name="advance_application[disbursement_bank]" label="Bank / SACCO name" :value="old('advance_application.disbursement_bank')" />
                                    <x-input name="advance_application[disbursement_branch]" label="Branch" :value="old('advance_application.disbursement_branch')" />
                                    <x-input name="advance_application[disbursement_account_name]" label="Account name" :value="old('advance_application.disbursement_account_name', $applicant?->full_name)" />
                                    <x-input name="advance_application[disbursement_account_number]" label="Account / mobile number" :value="old('advance_application.disbursement_account_number', $applicant?->phone)" />
                                </div>
                            </div>

                            <div class="border-t border-slate-100 pt-4 space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Payslips <span class="text-rose-500">*</span></label>
                                    <p class="mt-0.5 text-xs text-slate-500">Attach one or more recent payslips (PDF or image, up to 5 files).</p>
                                    <input type="file" name="payslips[]" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple x-bind:required="needsEmployerFinance()"
                                           class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand-700">
                                    @error('payslips')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                    @error('payslips.*')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-slate-700">Employment letter <span class="text-rose-500">*</span></label>
                                    <p class="mt-0.5 text-xs text-slate-500">A letter confirming your employment and salary.</p>
                                    <input type="file" name="employment_letter" accept=".pdf,.jpg,.jpeg,.png,.webp" x-bind:required="needsEmployerFinance()"
                                           class="mt-2 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand-700">
                                    @error('employment_letter')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                </div>

                                <x-signature-pad name="signature"
                                                 label="Your online signature"
                                                 help="Draw your signature to authorise the application and the employer remittance form."
                                                 :required="true" />
                                @error('signature')<p class="-mt-2 text-xs text-rose-600">{{ $message }}</p>@enderror
                            </div>
                        </div>

                        <p class="mt-4 rounded-lg bg-teal-50 px-3 py-2 text-xs text-teal-800">
                            After submitting, download the remittance form from your loan page. Your employer finance office
                            fills in the officer name, signature, date and official stamp agreeing to remit the advance/due amount to the
                            account or paybill shown, then you upload the completed form back here.
                        </p>
                    </x-card>
                </template>
            </div>

            <div class="space-y-5">
                <x-collapsible-card title="What this product requires" subtitle="Rules for the selected loan type. Click to expand.">
                    <p x-show="!product" class="text-sm text-slate-500">Select a loan product to see its limits and requirements.</p>

                    <div x-cloak x-show="product" class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Borrow</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? money(product.min_amount) + ' – ' + money(product.max_amount) : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Term</span>
                            <span class="font-medium text-slate-900" x-text="product ? product.duration_min + '–' + product.duration_max + ' ' + product.duration_unit : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Borrowing limit</span>
                            <span class="font-medium text-slate-900" x-text="product ? multiplier(product.multiplier_bps) + ' qualifying savings' : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Interest</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? percent(product.rate_bps) + '% per ' + product.interest_period : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Fees</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? percent(product.processing_fee_bps) + '% processing · ' + percent(product.insurance_fee_bps) + '% insurance' : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Guarantors</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? guarantorLabel(product) : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Late penalty</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? percent(product.penalty_rate_bps) + '% per ' + product.penalty_period + ' after ' + product.penalty_grace_days + ' day(s)' : ''"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-slate-500">Eligibility</span>
                            <span class="text-right font-medium text-slate-900" x-text="product ? eligibility(product) : ''"></span>
                        </div>
                    </div>
                </x-collapsible-card>

                <x-card title="Quotation">
                    <button type="button" @click="loadQuote()" :disabled="loading"
                            class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-60">
                        <x-icon name="chart" class="h-4 w-4" />
                        <span x-text="loading ? 'Calculating…' : 'Calculate quotation'"></span>
                    </button>

                    <p x-cloak x-show="error" x-text="error" class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700"></p>

                    <dl x-cloak x-show="quote" class="mt-4 space-y-2.5 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Principal</dt>
                            <dd class="font-semibold text-slate-900" x-text="money(quote?.principal)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Interest</dt>
                            <dd class="font-medium text-slate-800" x-text="money(quote?.total_interest)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Fees</dt>
                            <dd class="font-medium text-slate-800" x-text="money(quote?.total_fees)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="font-medium text-slate-700">Total repayable</dt>
                            <dd class="font-semibold text-slate-900" x-text="money(quote?.total_repayable)"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Installments</dt>
                            <dd class="font-medium text-slate-800" x-text="quote?.installment_count"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">First due</dt>
                            <dd class="font-medium text-slate-800" x-text="quote?.first_due_date"></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">Maturity</dt>
                            <dd class="font-medium text-slate-800" x-text="quote?.maturity_date"></dd>
                        </div>
                    </dl>

                    <div x-cloak x-show="capacity" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3 text-xs">
                            <span class="text-slate-500">Your eligible amount</span>
                            <span class="font-semibold text-slate-900" x-text="money(capacity?.final_eligible)"></span>
                        </div>
                        <template x-if="capacity?.blockers?.length">
                            <ul class="mt-2 space-y-1 text-xs text-rose-700">
                                <template x-for="blocker in capacity.blockers" :key="blocker">
                                    <li x-text="'• ' + blocker"></li>
                                </template>
                            </ul>
                        </template>
                    </div>

                    <p x-cloak x-show="!quote && !error" class="mt-3 text-xs text-slate-500">
                        Choose a product, amount and duration to preview the repayment schedule.
                    </p>
                </x-card>

                <x-card title="Submit">
                    <p class="text-sm text-slate-600">
                        Your application is sent to the group's loan committee. Guarantee requests go out immediately when the product requires guarantors.
                    </p>
                    <button type="submit" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                        <x-icon name="check" class="h-4 w-4" /> Submit application
                    </button>
                    <a href="{{ route('portal.loans.index') }}" class="mt-2 block text-center text-sm font-medium text-slate-500 hover:text-slate-700">Cancel</a>
                </x-card>
            </div>

            <div x-cloak x-show="quote" class="xl:col-span-3">
                <x-card title="Indicative repayment schedule" :padding="false">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-2.5">#</th>
                                    <th class="px-4 py-2.5">Due date</th>
                                    <th class="px-4 py-2.5 text-right">Principal</th>
                                    <th class="px-4 py-2.5 text-right">Interest</th>
                                    <th class="px-4 py-2.5 text-right">Fees</th>
                                    <th class="px-4 py-2.5 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="line in (quote?.lines ?? [])" :key="line.installment_no">
                                    <tr>
                                        <td class="px-4 py-2.5 text-slate-600" x-text="line.installment_no"></td>
                                        <td class="px-4 py-2.5 text-slate-600" x-text="line.due_date"></td>
                                        <td class="px-4 py-2.5 text-right text-slate-600" x-text="money(line.principal_due)"></td>
                                        <td class="px-4 py-2.5 text-right text-slate-600" x-text="money(line.interest_due)"></td>
                                        <td class="px-4 py-2.5 text-right text-slate-600" x-text="money(line.fee_due)"></td>
                                        <td class="px-4 py-2.5 text-right font-medium text-slate-900" x-text="money(line.total_due)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </x-card>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        function portalLoanApplication({ productMeta, memberOptions, quoteUrl, memberRequiresEmployerFinance }) {
            return {
                productMeta,
                memberOptions,
                quoteUrl,
                memberRequiresEmployerFinance: Boolean(memberRequiresEmployerFinance),
                productId: '',
                amount: '',
                duration: '',
                durationUnit: 'months',
                guarantors: [],
                nonMemberGuarantors: [],
                quote: null,
                capacity: null,
                error: null,
                loading: false,

                init() {
                    this.$watch('productId', (id) => {
                        const product = this.productMeta[id];

                        if (product) {
                            if (! this.duration) {
                                this.duration = product.duration_min || 1;
                            }

                            this.durationUnit = product.duration_unit || 'months';
                        }
                    });
                },

                get product() {
                    return this.productMeta[this.productId] ?? null;
                },

                needsEmployerFinance() {
                    return this.memberRequiresEmployerFinance || Boolean(this.product && this.product.requires_employer_remittance);
                },

                percent(bps) {
                    return (Number(bps || 0) / 100).toFixed(2);
                },

                guarantorLabel(product) {
                    if (product.guarantor_mode === 'none') {
                        return 'Not used (savings multiplier only)';
                    }

                    if (product.guarantor_mode === 'required' || product.requires_guarantors) {
                        return 'Required: at least ' + product.min_guarantors + ' · ' + this.percent(product.guarantor_coverage_bps) + '% cover';
                    }

                    return 'Optional (with or without guarantors)';
                },

                multiplier(bps) {
                    return (Number(bps || 0) / 10000).toFixed(2) + 'x';
                },

                eligibility(product) {
                    const parts = [];

                    if (Number(product.min_membership_days) > 0) {
                        parts.push('member for ' + product.min_membership_days + '+ days');
                    }

                    if (Number(product.max_concurrent_loans) === 0) {
                        parts.push('no other active loans');
                    } else {
                        parts.push('up to ' + product.max_concurrent_loans + ' concurrent loan(s)');
                    }

                    if (product.requires_no_arrears) {
                        parts.push('no arrears');
                    }

                    return parts.join(' · ');
                },

                money(minor) {
                    const value = Number(minor || 0) / 100;

                    return 'KES ' + value.toLocaleString('en-KE', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                },

                async loadQuote() {
                    this.error = null;
                    this.loading = true;

                    try {
                        const response = await fetch(this.quoteUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({
                                loan_product_id: this.productId,
                                amount: this.amount,
                                duration: this.duration,
                                duration_unit: this.durationUnit,
                            }),
                        });

                        const data = await response.json().catch(() => ({}));

                        if (! response.ok) {
                            this.error = data.message || 'The quotation could not be calculated.';
                            this.quote = null;
                            this.capacity = null;

                            return;
                        }

                        this.quote = data.quotation;
                        this.capacity = data.multiplier;
                    } catch (e) {
                        this.error = 'Unable to reach the quotation service.';
                        this.quote = null;
                        this.capacity = null;
                    } finally {
                        this.loading = false;
                    }
                },
            };
        }
    </script>
@endpush
