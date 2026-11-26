<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class LedgerLine extends Model
{
    use BelongsToGroup;

    public const UPDATED_AT = null;

    protected $fillable = [
        'group_id', 'journal_entry_id', 'ledger_account_id', 'member_id',
        'debit', 'credit', 'description',
    ];

    protected function casts(): array
    {
        return ['debit' => 'integer', 'credit' => 'integer', 'created_at' => 'datetime'];
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account()
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
