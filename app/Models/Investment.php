<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Investment extends Model
{
    use Auditable;
    use BelongsToGroup;
    use OwnedByMember;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_MATURED = 'matured';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'investment_product_id', 'reference_no',
        'principal', 'interest_rate_bps', 'interest_period', 'interest_method',
        'duration_months', 'start_date', 'maturity_date', 'expected_interest',
        'accrued_interest', 'paid_interest', 'status', 'last_accrued_at',
        'closed_at', 'notes', 'terms_snapshot', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'integer',
            'interest_rate_bps' => 'integer',
            'duration_months' => 'integer',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'expected_interest' => 'integer',
            'accrued_interest' => 'integer',
            'paid_interest' => 'integer',
            'last_accrued_at' => 'datetime',
            'closed_at' => 'datetime',
            'terms_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Investment $investment) => $investment->uuid ??= (string) Str::uuid());
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function product()
    {
        return $this->belongsTo(InvestmentProduct::class, 'investment_product_id');
    }

    public function schedules()
    {
        return $this->hasMany(InvestmentSchedule::class)->orderBy('period_no');
    }

    public function transactions()
    {
        return $this->hasMany(InvestmentTransaction::class)->latest('transaction_date')->latest('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_MATURED], true);
    }

    public function isMatured(): bool
    {
        return $this->status === self::STATUS_MATURED
            || ($this->maturity_date && $this->maturity_date->isPast() && $this->status === self::STATUS_ACTIVE);
    }

    public function totalValue(): int
    {
        return max(0, $this->principal + $this->accrued_interest - $this->paid_interest);
    }

    public function expectedTotal(): int
    {
        return $this->principal + $this->expected_interest;
    }

    public function progressPercent(): int
    {
        if (! $this->start_date || ! $this->maturity_date) {
            return 0;
        }

        $total = max(1, (int) $this->start_date->diffInDays($this->maturity_date));
        $elapsed = max(0, min($total, (int) $this->start_date->diffInDays(now(), false)));

        return (int) round(($elapsed / $total) * 100);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => $this->isMatured() ? 'Matured · ready to withdraw' : 'Active',
            self::STATUS_MATURED => 'Matured · ready to withdraw',
            self::STATUS_WITHDRAWN => 'Withdrawn',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst((string) $this->status),
        };
    }
}
