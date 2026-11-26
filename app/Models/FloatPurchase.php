<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FloatPurchase extends Model
{
    use BelongsToGroup;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending fulfilment',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_REFUNDED => 'Refunded',
    ];

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'reference', 'network', 'phone',
        'agent_id', 'agent_number', 'store_number', 'agent_name',
        'amount', 'charge_amount', 'total_amount', 'group_fee_amount',
        'currency', 'status', 'provider', 'provider_reference', 'idempotency_key',
        'requested_by', 'fulfilled_by', 'payload', 'failure_reason',
        'journal_entry_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'charge_amount' => 'integer',
            'total_amount' => 'integer',
            'group_fee_amount' => 'integer',
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (FloatPurchase $purchase) {
            $purchase->uuid ??= (string) Str::uuid();
            $purchase->reference ??= 'FLT-'.strtoupper(Str::random(10));
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(MpesaAgent::class, 'agent_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_REFUNDED,
        ], true);
    }
}
