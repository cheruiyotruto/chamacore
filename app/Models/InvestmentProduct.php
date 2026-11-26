<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class InvestmentProduct extends Model
{
    use BelongsToGroup;

    public const TYPE_FIXED_TERM = 'fixed_term';
    public const TYPE_NOTICE = 'notice';
    public const TYPE_UNIT_TRUST = 'unit_trust';

    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_QUARTERLY = 'quarterly';
    public const PERIOD_SEMI_ANNUAL = 'semi_annual';
    public const PERIOD_ANNUAL = 'annual';
    public const PERIOD_AT_MATURITY = 'at_maturity';

    public const METHOD_SIMPLE = 'simple';
    public const METHOD_COMPOUND = 'compound';

    public const TYPES = [
        self::TYPE_FIXED_TERM => 'Fixed term deposit',
        self::TYPE_NOTICE => 'Notice investment',
        self::TYPE_UNIT_TRUST => 'Unit trust',
    ];

    public const PERIODS = [
        self::PERIOD_MONTHLY => 'Monthly interest period',
        self::PERIOD_QUARTERLY => 'Quarterly interest period',
        self::PERIOD_SEMI_ANNUAL => 'Semi-annual interest period',
        self::PERIOD_ANNUAL => 'Annual interest period',
        self::PERIOD_AT_MATURITY => 'Single period at maturity',
    ];

    public const METHODS = [
        self::METHOD_SIMPLE => 'Simple interest',
        self::METHOD_COMPOUND => 'Compound interest',
    ];

    protected $fillable = [
        'group_id', 'name', 'code', 'type', 'description', 'interest_rate_bps',
        'interest_period', 'interest_method', 'duration_months', 'min_amount',
        'max_amount', 'early_withdrawal_allowed', 'early_withdrawal_penalty_bps',
        'is_active', 'terms',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate_bps' => 'integer',
            'duration_months' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'early_withdrawal_allowed' => 'boolean',
            'early_withdrawal_penalty_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function investments()
    {
        return $this->hasMany(Investment::class);
    }

    public function periodLabel(): string
    {
        return self::PERIODS[$this->interest_period] ?? ucfirst(str_replace('_', ' ', (string) $this->interest_period));
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->interest_method] ?? ucfirst((string) $this->interest_method);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }
}
