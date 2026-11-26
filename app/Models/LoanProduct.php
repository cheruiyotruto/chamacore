<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use App\Services\Loans\LoanCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LoanProduct extends Model
{
    use Auditable;
    use BelongsToGroup;
    use SoftDeletes;

    /**
     * Built-in product types. Groups may create their own type by choosing the
     * "custom" option; the resolved slug is stored in the category column.
     */
    public const CATEGORIES = [
        'salary_advance' => 'Salary advance',
        'business' => 'Business',
        'emergency' => 'Emergency',
        'education' => 'Education',
        'agriculture' => 'Agriculture',
        'personal' => 'Personal',
        'asset' => 'Asset finance',
        'other' => 'Other',
    ];

    public const CUSTOM_CATEGORY = 'custom';

    protected $fillable = [
        'group_id', 'name', 'code', 'category', 'description', 'interest_method',
        'interest_rate_bps', 'interest_period', 'accrual_mode', 'day_count',
        'repayment_frequency', 'repayment_day', 'duration_min', 'duration_max',
        'duration_unit', 'min_amount', 'max_amount', 'multiplier_bps',
        'guarantee_multiplier_bps', 'qualifying_savings_product_ids', 'include_shares',
        'share_weight_bps', 'requires_guarantors', 'guarantor_mode', 'requires_employer_remittance', 'min_guarantors',
        'guarantor_coverage_bps', 'penalty_rate_bps', 'penalty_period',
        'penalty_grace_days', 'processing_fee_bps', 'insurance_fee_bps',
        'allocation_policy', 'min_membership_days', 'max_concurrent_loans',
        'requires_no_arrears', 'is_active', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate_bps' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'multiplier_bps' => 'integer',
            'guarantee_multiplier_bps' => 'integer',
            'qualifying_savings_product_ids' => 'array',
            'include_shares' => 'boolean',
            'share_weight_bps' => 'integer',
            'requires_guarantors' => 'boolean',
            'requires_employer_remittance' => 'boolean',
            'min_guarantors' => 'integer',
            'guarantor_coverage_bps' => 'integer',
            'penalty_rate_bps' => 'integer',
            'penalty_grace_days' => 'integer',
            'processing_fee_bps' => 'integer',
            'insurance_fee_bps' => 'integer',
            'allocation_policy' => 'array',
            'min_membership_days' => 'integer',
            'max_concurrent_loans' => 'integer',
            'requires_no_arrears' => 'boolean',
            'is_active' => 'boolean',
            'settings' => 'array',
            'repayment_day' => 'integer',
            'duration_min' => 'integer',
            'duration_max' => 'integer',
        ];
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    public function workflow()
    {
        return $this->hasOne(ApprovalWorkflow::class)->where('is_active', true);
    }

    public function allocationPolicy(): array
    {
        return $this->allocation_policy
            ?: config('chama.financial.default_allocation_policy', ['penalties', 'fees', 'interest', 'principal']);
    }

    public function guarantorMode(): string
    {
        $mode = $this->guarantor_mode;

        if (in_array($mode, ['none', 'optional', 'required'], true)) {
            return $mode;
        }

        return $this->requires_guarantors ? 'required' : 'optional';
    }

    public function guarantorsRequired(): bool
    {
        return $this->guarantorMode() === 'required';
    }

    public function guarantorsAllowed(): bool
    {
        return $this->guarantorMode() !== 'none';
    }

    public function guarantorModeLabel(): string
    {
        return match ($this->guarantorMode()) {
            'none' => 'Not used',
            'required' => 'Required',
            default => 'Optional',
        };
    }

    public function requiresEmployerRemittance(): bool
    {
        return (bool) $this->requires_employer_remittance
            || in_array($this->category, ['salary_advance', 'advance'], true);
    }

    public function processingFeeFor(int $principal): int
    {
        return intdiv($principal * $this->processing_fee_bps, 10000);
    }

    public function insuranceFeeFor(int $principal): int
    {
        return intdiv($principal * $this->insurance_fee_bps, 10000);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? Str::headline((string) $this->category);
    }

    public function methodLabel(): string
    {
        return match ($this->interest_method) {
            LoanCalculator::METHOD_FLAT => 'Flat rate',
            LoanCalculator::METHOD_REDUCING_ANNUITY => 'Reducing balance (equal installments)',
            LoanCalculator::METHOD_REDUCING_EQUAL_PRINCIPAL => 'Reducing balance (equal principal)',
            LoanCalculator::METHOD_FIXED => 'Fixed interest',
            default => $this->interest_method,
        };
    }
}
