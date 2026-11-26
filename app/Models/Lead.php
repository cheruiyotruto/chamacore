<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'name', 'phone', 'email', 'source', 'status',
        'assigned_to', 'converted_member_id', 'notes', 'follow_up_at',
    ];

    protected function casts(): array
    {
        return ['follow_up_at' => 'datetime'];
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedMember()
    {
        return $this->belongsTo(Member::class, 'converted_member_id');
    }

    public function followUps()
    {
        return $this->hasMany(FollowUp::class);
    }
}
