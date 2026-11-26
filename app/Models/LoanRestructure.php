<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LoanRestructure extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'loan_id', 'old_terms', 'new_terms', 'reason',
        'effective_date', 'status', 'requested_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'old_terms' => 'array',
            'new_terms' => 'array',
            'effective_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
