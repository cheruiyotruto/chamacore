<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email', 'ip_address', 'user_agent', 'successful', 'failure_reason'];

    protected function casts(): array
    {
        return ['successful' => 'boolean', 'created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
