<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class GroupPolicy extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'title', 'type', 'content', 'version', 'effective_date',
        'status', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'approved_at' => 'datetime'];
    }
}
