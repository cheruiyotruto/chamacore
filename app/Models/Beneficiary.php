<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Beneficiary extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'member_id', 'name', 'relationship', 'phone', 'id_number',
        'allocation_percent', 'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'allocation_percent' => 'integer'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
