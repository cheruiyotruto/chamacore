<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payout extends Model
{
    use BelongsToGroup;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_BILLS = 'bill_collections';
    public const SOURCE_WALLET = 'wallet';

    protected $fillable = [
        'uuid', 'group_id', 'destination_id', 'source', 'bill_id', 'member_id',
        'amount', 'net_amount', 'charge_amount', 'total_amount', 'charge_breakdown',
        'borne_by', 'currency', 'provider', 'status', 'provider_reference',
        'idempotency_key', 'requested_by', 'approved_by', 'payload', 'failure_reason',
        'journal_entry_id', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'net_amount' => 'integer',
            'charge_amount' => 'integer',
            'total_amount' => 'integer',
            'charge_breakdown' => 'array',
            'payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Payout $payout) => $payout->uuid ??= (string) Str::uuid());
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(PaymentDestination::class, 'destination_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED,
        ], true);
    }
}
