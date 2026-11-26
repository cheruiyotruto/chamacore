<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'price', 'interval', 'trial_days', 'max_groups',
        'max_members', 'modules', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'trial_days' => 'integer',
            'max_groups' => 'integer',
            'max_members' => 'integer',
            'modules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function priceLabel(): string
    {
        return \App\Support\Money::format($this->price).' / '.$this->interval;
    }
}
