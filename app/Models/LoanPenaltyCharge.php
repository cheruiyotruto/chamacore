<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LoanPenaltyCharge extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'loan_id', 'installment_id', 'amount', 'period_start',
        'period_end', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function installment()
    {
        return $this->belongsTo(LoanSchedule::class, 'installment_id');
    }
}
