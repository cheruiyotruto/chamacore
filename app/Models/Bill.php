<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Bill extends Model
{
    use Auditable;
    use BelongsToGroup;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_PAID = 'partially_paid';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'bill_no', 'payee_name', 'payee_account',
        'phone', 'amount', 'amount_paid', 'currency', 'due_date', 'status',
        'description', 'metadata', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'amount_paid' => 'integer',
            'due_date' => 'date',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Bill $bill) {
            $bill->uuid ??= (string) Str::uuid();
            $bill->bill_no ??= 'BILL-'.now()->format('Y').'-'.strtoupper(Str::random(8));
            $bill->currency ??= 'KES';
            $bill->status ??= self::STATUS_PENDING;
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * Amount collected for this bill that is available to send to the biller.
     * Charge lines that were deducted from the payer do not form part of the
     * amount owed to the biller; group-borne charges are paid separately.
     */
    public function settlementBalance(): int
    {
        $collected = (int) $this->payments()->sum('settlement_amount');
        $sent = (int) $this->payouts()
            ->whereNotIn('status', [Payout::STATUS_FAILED, Payout::STATUS_CANCELLED])
            ->sum('amount');

        return max(0, $collected - $sent);
    }

    public function outstanding(): int
    {
        return max(0, $this->amount - $this->amount_paid);
    }

    public function isFullyPaid(): bool
    {
        return $this->outstanding() === 0;
    }

    public function isOverdue(): bool
    {
        return ! $this->isFullyPaid()
            && $this->status !== self::STATUS_CANCELLED
            && $this->due_date?->isPast() === true;
    }

    public function payeeLabel(): string
    {
        return $this->payee_account
            ? $this->payee_name.' · '.$this->payee_account
            : $this->payee_name;
    }

    public function refreshStatus(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return $this->status;
        }

        if ($this->isFullyPaid()) {
            return $this->status = self::STATUS_PAID;
        }

        if ($this->amount_paid > 0) {
            return $this->status = self::STATUS_PARTIALLY_PAID;
        }

        return $this->status = $this->isOverdue() ? self::STATUS_OVERDUE : self::STATUS_PENDING;
    }
}
