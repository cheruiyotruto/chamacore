<?php

namespace App\Http\Controllers;

use App\Models\LoanProduct;
use App\Models\SavingsProduct;
use App\Services\AuditService;
use App\Services\Loans\LoanCalculator;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoanProductController extends Controller
{
    private const METHODS = [
        LoanCalculator::METHOD_FLAT => 'Flat rate',
        LoanCalculator::METHOD_REDUCING_ANNUITY => 'Reducing balance (equal installments)',
        LoanCalculator::METHOD_REDUCING_EQUAL_PRINCIPAL => 'Reducing balance (equal principal)',
        LoanCalculator::METHOD_FIXED => 'Fixed interest',
    ];

    /**
     * Repayment allocation order. The key is stored in the form, the value is
     * persisted to the product's allocation_policy JSON column.
     */
    private const ALLOCATION_POLICIES = [
        'standard' => ['penalties', 'fees', 'interest', 'principal'],
        'fees_first' => ['fees', 'penalties', 'interest', 'principal'],
        'interest_first' => ['interest', 'fees', 'penalties', 'principal'],
    ];

    /**
     * Suggested starting requirements for each product type. These only seed
     * the create form; every value remains editable per product.
     */
    private const TYPE_TEMPLATES = [
        'salary_advance' => [
            'interest_method' => LoanCalculator::METHOD_FLAT,
            'interest_rate' => '5.00',
            'duration_min' => 1,
            'duration_max' => 3,
            'min_amount' => '1000.00',
            'max_amount' => '30000.00',
            'multiplier' => '0',
            'guarantee_multiplier' => '0',
            'guarantor_mode' => 'optional',
            'min_guarantors' => 0,
            'requires_employer_remittance' => true,
            'processing_fee' => '1.00',
        ],
        'business' => [
            'interest_method' => LoanCalculator::METHOD_REDUCING_ANNUITY,
            'interest_rate' => '12.00',
            'duration_min' => 3,
            'duration_max' => 24,
            'min_amount' => '5000.00',
            'max_amount' => '50000.00',
            'multiplier' => '300',
            'guarantor_mode' => 'optional',
            'min_guarantors' => 1,
            'processing_fee' => '1.50',
        ],
        'emergency' => [
            'interest_method' => LoanCalculator::METHOD_REDUCING_ANNUITY,
            'interest_rate' => '10.00',
            'duration_min' => 1,
            'duration_max' => 6,
            'min_amount' => '1000.00',
            'max_amount' => '20000.00',
            'multiplier' => '200',
            'guarantor_mode' => 'optional',
            'min_guarantors' => 1,
            'processing_fee' => '1.00',
        ],
        'education' => [
            'interest_method' => LoanCalculator::METHOD_REDUCING_EQUAL_PRINCIPAL,
            'interest_rate' => '8.00',
            'duration_min' => 3,
            'duration_max' => 12,
            'min_amount' => '2000.00',
            'max_amount' => '30000.00',
            'multiplier' => '250',
            'guarantor_mode' => 'optional',
            'min_guarantors' => 1,
            'processing_fee' => '1.00',
        ],
        'agriculture' => [
            'interest_method' => LoanCalculator::METHOD_FLAT,
            'interest_rate' => '6.00',
            'interest_period' => 'annual',
            'duration_min' => 3,
            'duration_max' => 18,
            'min_amount' => '3000.00',
            'max_amount' => '40000.00',
            'multiplier' => '250',
            'guarantor_mode' => 'optional',
            'min_guarantors' => 1,
            'processing_fee' => '1.00',
        ],
        'personal' => [
            'interest_method' => LoanCalculator::METHOD_REDUCING_ANNUITY,
            'interest_rate' => '14.00',
            'duration_min' => 1,
            'duration_max' => 12,
            'min_amount' => '1000.00',
            'max_amount' => '15000.00',
            'multiplier' => '200',
            'guarantor_mode' => 'optional',
            'min_guarantors' => 1,
            'processing_fee' => '1.50',
        ],
        'asset' => [
            'interest_method' => LoanCalculator::METHOD_REDUCING_ANNUITY,
            'interest_rate' => '10.00',
            'duration_min' => 6,
            'duration_max' => 36,
            'min_amount' => '10000.00',
            'max_amount' => '100000.00',
            'multiplier' => '350',
            'guarantor_mode' => 'optional',
            'min_guarantors' => 1,
            'processing_fee' => '2.00',
        ],
    ];

    public function index(): View
    {
        $this->requirePermission('loans.view');

        return view('loans.products.index', [
            'products' => LoanProduct::withCount('loans')->orderByDesc('is_active')->orderBy('name')->get(),
            'canManage' => $this->context()->hasPermission('settings.manage'),
        ]);
    }

    public function create(Request $request): View
    {
        $this->requirePermission('settings.manage');

        $template = $request->string('template')->toString();
        $values = $this->defaultValues();

        if ($template !== '' && isset(self::TYPE_TEMPLATES[$template])) {
            $values = array_merge($values, self::TYPE_TEMPLATES[$template], ['category' => $template]);
        }

        return view('loans.products.form', $this->formData($values, null, $template));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        try {
            $product = LoanProduct::create($this->validated($request));
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'code' => 'A loan product with this code already exists in this group.',
            ]);
        }

        AuditService::record('loan_product.created', $product, null, $this->auditValues($product));

        return redirect()->route('loan-products.index')
            ->with('status', 'Loan product "'.$product->name.'" created.');
    }

    public function edit(LoanProduct $product): View
    {
        $this->requirePermission('settings.manage');

        return view('loans.products.form', $this->formData($this->formValues($product), $product));
    }

    public function update(Request $request, LoanProduct $product): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $old = $this->auditValues($product);

        try {
            $product->update($this->validated($request, $product));
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages([
                'code' => 'A loan product with this code already exists in this group.',
            ]);
        }

        AuditService::record('loan_product.updated', $product, $old, $this->auditValues($product));

        return redirect()->route('loan-products.index')
            ->with('status', 'Loan product "'.$product->name.'" updated.');
    }

    public function toggle(LoanProduct $product): RedirectResponse
    {
        $this->requirePermission('settings.manage');

        $product->update(['is_active' => ! $product->is_active]);

        AuditService::record('loan_product.status_changed', $product, null, ['is_active' => $product->is_active]);

        return back()->with('status', 'Loan product "'.$product->name.'" is now '.($product->is_active ? 'active' : 'inactive').'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(array $values, ?LoanProduct $product = null, ?string $template = null): array
    {
        return [
            'values' => $values,
            'product' => $product,
            'template' => $template,
            'categories' => LoanProduct::CATEGORIES + [
                LoanProduct::CUSTOM_CATEGORY => 'Other (custom type)',
            ],
            'methods' => self::METHODS,
            'interestPeriods' => ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'annual' => 'Annual'],
            'dayCounts' => [
                'actual_365' => 'Actual/365',
                'actual_366' => 'Actual/366',
                'thirty_360' => '30/360',
            ],
            'accrualModes' => ['effective' => 'Effective (compounded)', 'simple' => 'Simple'],
            'frequencies' => ['weekly' => 'Weekly', 'monthly' => 'Monthly'],
            'durationUnits' => ['days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months'],
            'penaltyPeriods' => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'],
            'guarantorModes' => [
                'none' => 'Not used (savings multiplier only)',
                'optional' => 'Optional (with or without guarantors)',
                'required' => 'Required',
            ],
            'allocationPolicies' => [
                'standard' => 'Penalties → Fees → Interest → Principal',
                'fees_first' => 'Fees → Penalties → Interest → Principal',
                'interest_first' => 'Interest → Fees → Penalties → Principal',
            ],
            'savingsProducts' => SavingsProduct::orderBy('name')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultValues(): array
    {
        return [
            'name' => '',
            'code' => '',
            'category' => 'business',
            'custom_category' => '',
            'description' => '',
            'interest_method' => LoanCalculator::METHOD_REDUCING_ANNUITY,
            'interest_rate' => '12.00',
            'interest_period' => 'monthly',
            'accrual_mode' => 'effective',
            'day_count' => 'actual_365',
            'repayment_frequency' => 'monthly',
            'repayment_day' => '',
            'duration_min' => 1,
            'duration_max' => 12,
            'duration_unit' => 'months',
            'min_amount' => '0.00',
            'max_amount' => '50000.00',
            'multiplier' => '300',
            'guarantee_multiplier' => '300',
            'qualifying_savings_product_ids' => [],
            'include_shares' => false,
            'share_weight' => '100',
            'guarantor_mode' => 'required',
            'requires_employer_remittance' => false,
            'min_guarantors' => 1,
            'guarantor_coverage' => '100',
            'penalty_rate' => '2.00',
            'penalty_period' => 'daily',
            'penalty_grace_days' => 3,
            'processing_fee' => '0.00',
            'insurance_fee' => '0.00',
            'allocation_policy' => 'standard',
            'min_membership_days' => 0,
            'max_concurrent_loans' => 1,
            'requires_no_arrears' => true,
            'is_active' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formValues(LoanProduct $product): array
    {
        $category = (string) $product->category;
        $isCustomCategory = ! array_key_exists($category, LoanProduct::CATEGORIES);
        $policy = 'standard';

        foreach (self::ALLOCATION_POLICIES as $key => $value) {
            if ($product->allocation_policy === $value) {
                $policy = $key;

                break;
            }
        }

        return [
            'name' => $product->name,
            'code' => $product->code,
            'category' => $isCustomCategory ? LoanProduct::CUSTOM_CATEGORY : $category,
            'custom_category' => $isCustomCategory ? $product->categoryLabel() : '',
            'description' => $product->description ?? '',
            'interest_method' => $product->interest_method,
            'interest_rate' => $this->percentInput($product->interest_rate_bps),
            'interest_period' => $product->interest_period,
            'accrual_mode' => $product->accrual_mode,
            'day_count' => $product->day_count,
            'repayment_frequency' => $product->repayment_frequency,
            'repayment_day' => $product->repayment_day ?? '',
            'duration_min' => $product->duration_min,
            'duration_max' => $product->duration_max,
            'duration_unit' => $product->duration_unit,
            'min_amount' => $this->moneyInput($product->min_amount),
            'max_amount' => $this->moneyInput($product->max_amount),
            'multiplier' => $this->percentInput($product->multiplier_bps),
            'guarantee_multiplier' => $this->percentInput($product->guarantee_multiplier_bps),
            'qualifying_savings_product_ids' => $product->qualifying_savings_product_ids ?? [],
            'include_shares' => $product->include_shares,
            'share_weight' => $this->percentInput($product->share_weight_bps),
            'guarantor_mode' => $product->guarantorMode(),
            'requires_employer_remittance' => $product->requiresEmployerRemittance(),
            'min_guarantors' => $product->min_guarantors,
            'guarantor_coverage' => $this->percentInput($product->guarantor_coverage_bps),
            'penalty_rate' => $this->percentInput($product->penalty_rate_bps),
            'penalty_period' => $product->penalty_period,
            'penalty_grace_days' => $product->penalty_grace_days,
            'processing_fee' => $this->percentInput($product->processing_fee_bps),
            'insurance_fee' => $this->percentInput($product->insurance_fee_bps),
            'allocation_policy' => $policy,
            'min_membership_days' => $product->min_membership_days,
            'max_concurrent_loans' => $product->max_concurrent_loans,
            'requires_no_arrears' => $product->requires_no_arrears,
            'is_active' => $product->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?LoanProduct $product = null): array
    {
        $groupId = $this->group()->id;

        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code', ''))),
            'name' => trim((string) $request->input('name', '')),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required', 'string', 'max:30', 'alpha_dash',
                Rule::unique('loan_products', 'code')
                    ->where('group_id', $groupId)
                    ->ignore($product?->id),
            ],
            'category' => ['required', Rule::in([
                ...array_keys(LoanProduct::CATEGORIES),
                LoanProduct::CUSTOM_CATEGORY,
            ])],
            'custom_category' => [
                'nullable', 'string', 'max:50',
                'required_if:category,'.LoanProduct::CUSTOM_CATEGORY,
                'regex:/^[\pL\pN][\pL\pN _&\/-]*$/u',
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'interest_method' => ['required', Rule::in(array_keys(self::METHODS))],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['required', Rule::in(['weekly', 'monthly', 'annual'])],
            'accrual_mode' => ['required', Rule::in(['effective', 'simple'])],
            'day_count' => ['required', Rule::in(['actual_365', 'actual_366', 'thirty_360'])],
            'repayment_frequency' => ['required', Rule::in(['weekly', 'monthly'])],
            'repayment_day' => ['nullable', 'integer', 'between:1,31'],
            'duration_min' => ['required', 'integer', 'min:1', 'max:600'],
            'duration_max' => ['required', 'integer', 'min:1', 'max:600', 'gte:duration_min'],
            'duration_unit' => ['required', Rule::in(['days', 'weeks', 'months'])],
            'min_amount' => ['required', 'numeric', 'min:0'],
            'max_amount' => ['required', 'numeric', 'gt:min_amount'],
            'multiplier' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'guarantee_multiplier' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'qualifying_savings_product_ids' => ['nullable', 'array'],
            'qualifying_savings_product_ids.*' => ['integer', 'distinct'],
            'include_shares' => ['nullable', 'boolean'],
            'share_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'guarantor_mode' => ['required', Rule::in(['none', 'optional', 'required'])],
            'requires_employer_remittance' => ['nullable', 'boolean'],
            'min_guarantors' => ['required', 'integer', 'min:0', 'max:20'],
            'guarantor_coverage' => ['required', 'numeric', 'min:0', 'max:100'],
            'penalty_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'penalty_period' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'penalty_grace_days' => ['required', 'integer', 'min:0', 'max:365'],
            'processing_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'insurance_fee' => ['required', 'numeric', 'min:0', 'max:100'],
            'allocation_policy' => ['required', Rule::in(array_keys(self::ALLOCATION_POLICIES))],
            'min_membership_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'max_concurrent_loans' => ['required', 'integer', 'min:0', 'max:20'],
            'requires_no_arrears' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $savingsProductIds = collect($data['qualifying_savings_product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($savingsProductIds->isNotEmpty()) {
            $valid = SavingsProduct::query()->whereIn('id', $savingsProductIds)->pluck('id');

            if ($valid->count() !== $savingsProductIds->count()) {
                throw ValidationException::withMessages([
                    'qualifying_savings_product_ids' => 'One or more selected savings products do not belong to this group.',
                ]);
            }
        }

        $guarantorMode = (string) $data['guarantor_mode'];

        if ($guarantorMode === 'required' && (int) $data['min_guarantors'] < 1) {
            throw ValidationException::withMessages([
                'min_guarantors' => 'Set at least one guarantor when guarantees are required.',
            ]);
        }

        if ($guarantorMode === 'none') {
            $data['min_guarantors'] = 0;
        }

        $category = $data['category'];

        if ($category === LoanProduct::CUSTOM_CATEGORY) {
            $category = Str::slug((string) $data['custom_category'], '_');

            if ($category === '') {
                throw ValidationException::withMessages([
                    'custom_category' => 'Enter a name for this loan type.',
                ]);
            }
        }

        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'category' => $category,
            'description' => $data['description'] ?? null,
            'interest_method' => $data['interest_method'],
            'interest_rate_bps' => $this->toBps($data['interest_rate']),
            'interest_period' => $data['interest_period'],
            'accrual_mode' => $data['accrual_mode'],
            'day_count' => $data['day_count'],
            'repayment_frequency' => $data['repayment_frequency'],
            'repayment_day' => ($data['repayment_day'] ?? null) !== null ? (int) $data['repayment_day'] : null,
            'duration_min' => (int) $data['duration_min'],
            'duration_max' => (int) $data['duration_max'],
            'duration_unit' => $data['duration_unit'],
            'min_amount' => Money::toMinor($data['min_amount']),
            'max_amount' => Money::toMinor($data['max_amount']),
            'multiplier_bps' => $this->toBps($data['multiplier'] ?? 0),
            'guarantee_multiplier_bps' => $this->toBps($data['guarantee_multiplier'] ?? 0),
            'qualifying_savings_product_ids' => $savingsProductIds->all(),
            'include_shares' => $request->boolean('include_shares'),
            'share_weight_bps' => $this->toBps($data['share_weight'] ?? 100),
            'requires_guarantors' => $guarantorMode === 'required',
            'guarantor_mode' => $guarantorMode,
            'requires_employer_remittance' => $request->boolean('requires_employer_remittance'),
            'min_guarantors' => (int) $data['min_guarantors'],
            'guarantor_coverage_bps' => $this->toBps($data['guarantor_coverage']),
            'penalty_rate_bps' => $this->toBps($data['penalty_rate']),
            'penalty_period' => $data['penalty_period'],
            'penalty_grace_days' => (int) $data['penalty_grace_days'],
            'processing_fee_bps' => $this->toBps($data['processing_fee']),
            'insurance_fee_bps' => $this->toBps($data['insurance_fee']),
            'allocation_policy' => self::ALLOCATION_POLICIES[$data['allocation_policy']],
            'min_membership_days' => (int) $data['min_membership_days'],
            'max_concurrent_loans' => (int) $data['max_concurrent_loans'],
            'requires_no_arrears' => $request->boolean('requires_no_arrears'),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditValues(LoanProduct $product): array
    {
        return $product->only([
            'name', 'code', 'category', 'interest_method', 'interest_rate_bps',
            'interest_period', 'duration_min', 'duration_max', 'duration_unit',
            'min_amount', 'max_amount', 'multiplier_bps', 'requires_guarantors',
            'min_guarantors', 'penalty_rate_bps', 'processing_fee_bps',
            'insurance_fee_bps', 'is_active',
        ]);
    }

    private function toBps(string|int|float $percent): int
    {
        return (int) round(((float) $percent) * 100);
    }

    private function percentInput(?int $bps): string
    {
        return number_format(($bps ?? 0) / 100, 2, '.', '');
    }

    private function moneyInput(?int $minor): string
    {
        return number_format(($minor ?? 0) / 100, 2, '.', '');
    }
}
