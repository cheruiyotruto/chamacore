<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TellerSession extends Model
{
    use Auditable;
    use BelongsToGroup;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'uuid', 'group_id', 'user_id', 'status', 'opening_float', 'expected_cash',
        'counted_cash', 'variance', 'opened_at', 'closed_at', 'notes', 'closing_notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_float' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'variance' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (TellerSession $session) => $session->uuid ??= (string) Str::uuid());
    }

    public function teller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TellerEntry::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function cashIn(): int
    {
        return (int) $this->entries()
            ->where('method', 'cash')
            ->where('direction', TellerEntry::DIRECTION_IN)
            ->sum(\Illuminate\Support\Facades\DB::raw('amount + charge_amount'));
    }

    public function cashOut(): int
    {
        return (int) $this->entries()
            ->where('method', 'cash')
            ->where('direction', TellerEntry::DIRECTION_OUT)
            ->sum(\Illuminate\Support\Facades\DB::raw('amount - charge_amount'));
    }

    public function expectedCash(): int
    {
        return (int) $this->opening_float + $this->cashIn() - $this->cashOut();
    }

    public function variance(): int
    {
        return $this->counted_cash === null ? 0 : (int) $this->counted_cash - $this->expectedCash();
    }
}
