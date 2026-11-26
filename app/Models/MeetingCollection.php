<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class MeetingCollection extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'meeting_id', 'member_id', 'savings_product_id', 'type',
        'amount', 'reference', 'savings_transaction_id', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function product()
    {
        return $this->belongsTo(SavingsProduct::class, 'savings_product_id');
    }
}
