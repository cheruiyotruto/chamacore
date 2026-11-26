<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['group_id', 'key', 'channel', 'name', 'subject', 'body', 'is_active', 'variables'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'variables' => 'array'];
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function render(array $data): string
    {
        $body = $this->body;
        foreach ($data as $key => $value) {
            $body = str_replace(['{{'.$key.'}}', '{{ '.$key.' }}'], (string) $value, $body);
        }

        return $body;
    }
}
