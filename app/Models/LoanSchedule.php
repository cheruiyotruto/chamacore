<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LoanSchedule extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'loan_id', 'installment_no', 'due_date', 'principal_due',
        'interest_due', 'fee_due', 'penalty_due', 'total_due', 'principal_paid',
        'interest_paid', 'fee_paid', 'penalty_paid', 'status', 'paid_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'installment_no' => 'integer',
            'due_date' => 'date',
            'principal_due' => 'integer',
            'interest_due' => 'integer',
            'fee_due' => 'integer',
            'penalty_due' => 'integer',
            'total_due' => 'integer',
            'principal_paid' => 'integer',
            'interest_paid' => 'integer',
            'fee_paid' => 'integer',
            'penalty_paid' => 'integer',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function penaltyCharges()
    {
        return $this->hasMany(LoanPenaltyCharge::class, 'installment_id');
    }

    public function totalPaid(): int
    {
        return $this->principal_paid + $this->interest_paid + $this->fee_paid + $this->penalty_paid;
    }

    public function totalDue(): int
    {
        return $this->total_due + $this->penaltyDueExtra();
    }

    public function penaltyDueExtra(): int
    {
        return max(0, $this->penalty_due + (int) $this->penaltyCharges()->where('status', 'pending')->sum('amount'));
    }

    public function outstanding(): int
    {
        return max(0, $this->totalDue() - $this->totalPaid());
    }

    public function isOverdue(): bool
    {
        return $this->status !== 'paid' && $this->due_date->isPast();
    }
}
