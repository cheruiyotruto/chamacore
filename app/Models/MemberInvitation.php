<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MemberInvitation extends \Illuminate\Database\Eloquent\Model
{
    use Auditable;
    use BelongsToGroup;

    public const CHANNELS = ['email', 'sms', 'whatsapp'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'uuid', 'group_id', 'inviter_member_id', 'token', 'email', 'phone',
        'note', 'require_biometric', 'channels', 'delivery', 'status', 'expires_at', 'sent_at',
        'accepted_at', 'accepted_member_id',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'delivery' => 'array',
            'require_biometric' => 'boolean',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MemberInvitation $invitation) {
            $invitation->uuid ??= (string) Str::uuid();
            $invitation->token ??= Str::random(64);
        });
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'inviter_member_id');
    }

    public function acceptedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'accepted_member_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function effectiveStatus(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->isExpired()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function canBeUsed(): bool
    {
        return $this->effectiveStatus() === self::STATUS_PENDING;
    }

    public function requiresBiometric(): bool
    {
        return (bool) $this->require_biometric;
    }

    public function joinUrl(): string
    {
        return route('join.show', ['token' => $this->token]);
    }

    public function recipientLabel(): string
    {
        return $this->email ?: ($this->phone ?: 'Share link');
    }
}
