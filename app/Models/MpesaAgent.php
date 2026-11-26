<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A registered M-Pesa agent and one of the stores they operate.
 *
 * Float purchases are delivered to the store number. The agent number
 * identifies the agent account and the phone is the registered M-Pesa line.
 */
class MpesaAgent extends Model
{
    use BelongsToGroup;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_INACTIVE => 'Inactive',
    ];

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'name', 'agent_number', 'store_number',
        'phone', 'status', 'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (MpesaAgent $agent) {
            $agent->uuid ??= (string) Str::uuid();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(FloatPurchase::class, 'agent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function label(): string
    {
        return trim($this->name.' · Agent '.$this->agent_number.' · Store '.$this->store_number, ' ·');
    }
}
