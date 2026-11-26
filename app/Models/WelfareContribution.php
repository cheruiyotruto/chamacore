<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class WelfareContribution extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'welfare_fund_id', 'member_id', 'amount',
        'contribution_date', 'reference', 'savings_transaction_id', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'contribution_date' => 'date'];
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
