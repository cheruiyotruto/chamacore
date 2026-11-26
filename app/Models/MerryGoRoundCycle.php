<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class MerryGoRoundCycle extends Model
{
    use Auditable;
    use BelongsToGroup;

    protected $table = 'merry_go_round_cycles';

    protected $fillable = [
        'group_id', 'name', 'contribution_amount', 'frequency', 'day_of_week',
        'day_of_month', 'start_date', 'end_date', 'rotation_type', 'status',
        'penalty_rate_bps', 'penalty_grace_days', 'payout_requires_contributions',
        'min_contributions_before_payout', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'contribution_amount' => 'integer',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'penalty_rate_bps' => 'integer',
            'penalty_grace_days' => 'integer',
            'payout_requires_contributions' => 'boolean',
            'min_contributions_before_payout' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function participants()
    {
        return $this->hasMany(MerryGoRoundParticipant::class)->orderBy('position');
    }

    public function contributions()
    {
        return $this->hasMany(MerryGoRoundContribution::class);
    }

    public function payouts()
    {
        return $this->hasMany(MerryGoRoundPayout::class);
    }

    public function expectedPool(): int
    {
        return $this->contribution_amount * max(1, $this->participants()->count());
    }
}
