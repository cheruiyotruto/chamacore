<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use BelongsToGroup;

    public const UPDATED_AT = null;

    protected $fillable = [
        'group_id', 'member_id', 'channel', 'recipient', 'template_key',
        'payload', 'status', 'provider_reference', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
