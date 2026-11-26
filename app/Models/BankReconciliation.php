<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'bank_account_id', 'statement_date', 'statement_balance',
        'ledger_balance', 'difference', 'status', 'notes', 'reconciled_by',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_balance' => 'integer',
            'ledger_balance' => 'integer',
            'difference' => 'integer',
            'reconciled_at' => 'datetime',
        ];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
