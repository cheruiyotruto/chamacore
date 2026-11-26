<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class MerryGoRoundParticipant extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'merry_go_round_cycle_id', 'member_id', 'position',
        'payout_date', 'status', 'total_contributed', 'total_received',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'payout_date' => 'date',
            'total_contributed' => 'integer',
            'total_received' => 'integer',
        ];
    }

    public function cycle()
    {
        return $this->belongsTo(MerryGoRoundCycle::class, 'merry_go_round_cycle_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function contributions()
    {
        return $this->hasMany(MerryGoRoundContribution::class, 'participant_id');
    }

    public function payout()
    {
        return $this->hasOne(MerryGoRoundPayout::class, 'participant_id');
    }
}
