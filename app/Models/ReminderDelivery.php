<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'group_id', 'reminder_rule_id', 'member_id', 'sent_on',
    ];

    protected function casts(): array
    {
        return ['sent_on' => 'date', 'created_at' => 'datetime'];
    }

    public function rule()
    {
        return $this->belongsTo(ReminderRule::class, 'reminder_rule_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
