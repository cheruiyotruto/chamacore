<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'ledger_account_id', 'name', 'bank_name', 'account_no',
        'branch', 'opening_balance', 'current_balance', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'current_balance' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function ledgerAccount()
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function reconciliations()
    {
        return $this->hasMany(BankReconciliation::class);
    }
}
