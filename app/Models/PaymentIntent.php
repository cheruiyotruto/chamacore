<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentIntent extends Model
{
    use BelongsToGroup;
    use OwnedByMember;

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'payer_member_id', 'purpose', 'amount', 'provider', 'phone',
        'status', 'provider_reference', 'idempotency_key', 'payload',
        'failure_reason', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PaymentIntent $intent) => $intent->uuid ??= (string) Str::uuid());
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function payer()
    {
        return $this->belongsTo(Member::class, 'payer_member_id');
    }
}
