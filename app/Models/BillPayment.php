<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BillPayment extends Model
{
    use BelongsToGroup;

    protected $fillable = [
        'uuid', 'group_id', 'bill_id', 'member_id', 'payment_intent_id', 'amount',
        'settlement_amount', 'charge_amount', 'charge_breakdown', 'borne_by', 'method', 'reference',
        'journal_entry_id', 'recorded_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'settlement_amount' => 'integer',
            'charge_amount' => 'integer',
            'charge_breakdown' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (BillPayment $payment) => $payment->uuid ??= (string) Str::uuid());
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
