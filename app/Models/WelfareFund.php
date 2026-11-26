<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class WelfareFund extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'name', 'description', 'balance', 'contribution_amount',
        'contribution_frequency', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'contribution_amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function contributions()
    {
        return $this->hasMany(WelfareContribution::class);
    }

    public function claims()
    {
        return $this->hasMany(WelfareClaim::class);
    }
}
