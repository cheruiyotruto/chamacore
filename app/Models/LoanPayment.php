<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class LoanPayment extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'loan_id', 'member_id', 'amount', 'method', 'reference',
        'idempotency_key', 'allocation', 'status', 'paid_at', 'received_by',
        'journal_entry_id', 'wallet_transaction_id', 'reversal_of_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'allocation' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
