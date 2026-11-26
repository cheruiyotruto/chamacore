<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'title', 'type', 'scheduled_at', 'location', 'agenda',
        'minutes', 'status', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function attendances()
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    public function collections()
    {
        return $this->hasMany(MeetingCollection::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function presentCount(): int
    {
        return $this->attendances()->whereIn('status', ['present', 'late'])->count();
    }
}
