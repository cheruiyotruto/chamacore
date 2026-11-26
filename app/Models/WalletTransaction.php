<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use Auditable;
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'wallet_id', 'member_id', 'type', 'direction', 'amount',
        'balance_after', 'status', 'provider', 'provider_reference', 'reference',
        'idempotency_key', 'description', 'related_type', 'related_id',
        'journal_entry_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'balance_after' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isCredit(): bool
    {
        return $this->direction === 'credit';
    }
}
