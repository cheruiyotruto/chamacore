<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class MerryGoRoundContribution extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'merry_go_round_cycle_id', 'participant_id', 'member_id',
        'amount', 'due_date', 'paid_at', 'status', 'penalty_amount',
        'savings_transaction_id', 'reference', 'payment_intent_id', 'method',
        'charge_amount', 'charge_breakdown', 'borne_by', 'settlement_amount', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'penalty_amount' => 'integer',
            'settlement_amount' => 'integer',
            'charge_amount' => 'integer',
            'charge_breakdown' => 'array',
            'due_date' => 'date',
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

    public function paymentIntent()
    {
        return $this->belongsTo(PaymentIntent::class);
    }
}
