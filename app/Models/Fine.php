<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class Fine extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'member_id', 'meeting_id', 'loan_id', 'type', 'amount',
        'amount_paid', 'reason', 'status', 'due_date', 'waived_by', 'waived_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_paid' => 'integer',
            'due_date' => 'date',
            'waived_at' => 'datetime',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function outstanding(): int
    {
        return max(0, $this->amount - $this->amount_paid);
    }
}
