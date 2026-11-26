<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PaymentDestination extends Model
{
    use BelongsToGroup;

    public const TYPE_BANK = 'bank';
    public const TYPE_PAYBILL = 'paybill';
    public const TYPE_TILL = 'till';
    public const TYPE_PHONE = 'phone';

    public const TYPES = [
        self::TYPE_BANK => 'Bank account',
        self::TYPE_PAYBILL => 'M-Pesa PayBill',
        self::TYPE_TILL => 'M-Pesa Buy Goods (till)',
        self::TYPE_PHONE => 'M-Pesa phone number (B2C)',
    ];

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'name', 'type', 'bank_name', 'account_name',
        'account_number', 'shortcode', 'paybill_number', 'till_number', 'phone',
        'currency', 'is_active', 'metadata', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (PaymentDestination $destination) => $destination->uuid ??= (string) Str::uuid());
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'destination_id');
    }

    public function provider(): string
    {
        return $this->type === self::TYPE_BANK ? 'bank' : 'mpesa';
    }

    public function label(): string
    {
        return match ($this->type) {
            self::TYPE_BANK => trim($this->bank_name.' '.$this->account_number),
            self::TYPE_PAYBILL => 'PayBill '.$this->paybill_number.' · '.$this->account_name,
            self::TYPE_TILL => 'Till '.$this->till_number,
            self::TYPE_PHONE => $this->phone,
            default => $this->name,
        };
    }
}
