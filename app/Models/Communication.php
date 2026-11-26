<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Communication extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'group_id', 'member_id', 'lead_id', 'channel', 'direction', 'subject',
        'body', 'status', 'sent_at', 'metadata', 'created_by',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'metadata' => 'array'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
