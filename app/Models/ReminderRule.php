<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderRule extends Model
{
    public const TYPE_MGR_CONTRIBUTION = 'mgr_contribution';
    public const TYPE_MGR_PAYOUT = 'mgr_payout';
    public const TYPE_LOAN_REPAYMENT = 'loan_repayment';
    public const TYPE_BILL_DUE = 'bill_due';
    public const TYPE_INVESTMENT_MATURITY = 'investment_maturity';
    public const TYPE_CUSTOM_DATE = 'custom_date';

    public const TYPES = [
        self::TYPE_MGR_CONTRIBUTION => 'Merry-go-round contribution due',
        self::TYPE_MGR_PAYOUT => 'Merry-go-round payout date',
        self::TYPE_LOAN_REPAYMENT => 'Loan repayment due',
        self::TYPE_BILL_DUE => 'Bill due date',
        self::TYPE_INVESTMENT_MATURITY => 'Investment maturity',
        self::TYPE_CUSTOM_DATE => 'Custom date announcement',
    ];

    public const AUDIENCE_AUTO = 'auto';
    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_SELECTED = 'selected';

    public const AUDIENCES = [
        self::AUDIENCE_AUTO => 'People involved in the event',
        self::AUDIENCE_ALL => 'All active members',
        self::AUDIENCE_SELECTED => 'Selected members',
    ];

    protected $fillable = [
        'group_id', 'name', 'type', 'offset_days', 'specific_date', 'audience',
        'member_ids', 'template_key', 'subject', 'body', 'channels', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'specific_date' => 'date',
            'member_ids' => 'array',
            'channels' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function deliveries()
    {
        return $this->hasMany(ReminderDelivery::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function scheduleLabel(): string
    {
        if ($this->type === self::TYPE_CUSTOM_DATE) {
            return 'On '.$this->specific_date?->format('d M Y');
        }

        if ($this->offset_days === 0) {
            return 'On the event date';
        }

        return $this->offset_days < 0
            ? abs($this->offset_days).' day(s) before'
            : $this->offset_days.' day(s) after';
    }
}
