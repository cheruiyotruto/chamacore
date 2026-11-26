<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class MerryGoRoundPayout extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'merry_go_round_cycle_id', 'participant_id', 'member_id',
        'amount', 'scheduled_date', 'paid_at', 'status', 'journal_entry_id',
        'wallet_transaction_id', 'reference', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'scheduled_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function cycle()
    {
        return $this->belongsTo(MerryGoRoundCycle::class, 'merry_go_round_cycle_id');
    }

    public function participant()
    {
        return $this->belongsTo(MerryGoRoundParticipant::class, 'participant_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
