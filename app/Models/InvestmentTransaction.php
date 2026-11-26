<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class InvestmentTransaction extends Model
{
    use Auditable;
    use BelongsToGroup;
    use OwnedByMember;

    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_INTEREST = 'interest';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_MATURITY = 'maturity';
    public const TYPE_PENALTY = 'penalty';

    protected $fillable = [
        'group_id', 'investment_id', 'member_id', 'type', 'direction', 'amount',
        'balance_after', 'reference', 'description', 'idempotency_key',
        'journal_entry_id', 'transaction_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'transaction_date' => 'date',
        ];
    }

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }
}
