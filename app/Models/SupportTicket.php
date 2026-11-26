<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'subject', 'category', 'priority',
        'status', 'assigned_to', 'last_reply_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['last_reply_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (SupportTicket $ticket) => $ticket->uuid ??= (string) Str::uuid());
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function messages()
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }
}
