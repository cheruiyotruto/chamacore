<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class InvestmentSchedule extends Model
{
    use BelongsToGroup;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACCRUED = 'accrued';

    protected $fillable = [
        'group_id', 'investment_id', 'period_no', 'period_start', 'period_end',
        'due_date', 'opening_balance', 'interest_amount', 'closing_balance',
        'status', 'accrued_at', 'investment_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'period_no' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'opening_balance' => 'integer',
            'interest_amount' => 'integer',
            'closing_balance' => 'integer',
            'accrued_at' => 'datetime',
        ];
    }

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function transaction()
    {
        return $this->belongsTo(InvestmentTransaction::class, 'investment_transaction_id');
    }

    public function isAccrued(): bool
    {
        return $this->status === self::STATUS_ACCRUED;
    }
}
