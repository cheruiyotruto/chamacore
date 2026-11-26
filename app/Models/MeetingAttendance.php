<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class MeetingAttendance extends Model
{
    use BelongsToGroup;

    protected $fillable = ['group_id', 'meeting_id', 'member_id', 'status', 'fine_amount', 'notes'];

    protected function casts(): array
    {
        return ['fine_amount' => 'integer'];
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
