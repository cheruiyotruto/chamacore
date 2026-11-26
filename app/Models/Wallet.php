<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = ['group_id', 'member_id', 'balance', 'available_balance', 'status'];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'available_balance' => 'integer'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
