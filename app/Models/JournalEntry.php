<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'entry_no', 'entry_date', 'description', 'source_type',
        'source_id', 'status', 'reversal_of_id', 'posted_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(LedgerLine::class);
    }

    public function postedBy()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_of_id');
    }

    public function totalDebit(): int
    {
        return (int) $this->lines()->sum('debit');
    }

    public function totalCredit(): int
    {
        return (int) $this->lines()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalCredit();
    }
}
