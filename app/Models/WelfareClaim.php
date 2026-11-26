<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class WelfareClaim extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'welfare_fund_id', 'member_id', 'type', 'amount', 'reason',
        'status', 'approved_by', 'approved_at', 'disbursed_by', 'disbursed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
        ];
    }

    public function fund()
    {
        return $this->belongsTo(WelfareFund::class, 'welfare_fund_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
