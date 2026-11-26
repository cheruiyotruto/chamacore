<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class SavingsAccount extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'group_id', 'member_id', 'savings_product_id', 'account_no', 'balance',
        'available_balance', 'pledged_amount', 'status', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'available_balance' => 'integer',
            'pledged_amount' => 'integer',
            'opened_at' => 'datetime',
        ];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function product()
    {
        return $this->belongsTo(SavingsProduct::class, 'savings_product_id');
    }

    public function transactions()
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function eligibleBalance(): int
    {
        if (! $this->product?->qualifies_for_loan) {
            return 0;
        }

        return max(0, $this->balance - $this->pledged_amount);
    }
}
