<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class FollowUp extends Model
{
    use BelongsToGroup;

    protected $fillable = ['group_id', 'lead_id', 'assigned_to', 'due_at', 'notes', 'completed_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
