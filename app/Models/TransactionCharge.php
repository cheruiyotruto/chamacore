<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A group-level transaction charge. Charges can be fixed or a percentage,
 * can be mandatory or waivable, and can target all members, selected
 * membership categories or selected members.
 */
class TransactionCharge extends Model
{
    use Auditable;
    use BelongsToGroup;

    public const BASIS_FIXED = 'fixed';
    public const BASIS_PERCENT = 'percent';

    public const APPLIES_ALL = 'all';
    public const APPLIES_CATEGORIES = 'categories';
    public const APPLIES_MEMBERS = 'members';

    public const ALL_CATEGORIES = '*';

    protected $fillable = [
        'uuid', 'group_id', 'name', 'category', 'basis', 'amount', 'rate_bps',
        'min_amount', 'max_amount', 'is_mandatory', 'is_active', 'applies_to',
        'member_categories', 'income_account_code', 'description', 'sort_order',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'rate_bps' => 'integer',
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'member_categories' => 'array',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (TransactionCharge $charge) => $charge->uuid ??= (string) Str::uuid());
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'transaction_charge_member');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('category', $category)
            ->orWhere('category', self::ALL_CATEGORIES));
    }

    public function categoryLabel(): string
    {
        if ($this->category === self::ALL_CATEGORIES) {
            return 'All teller transactions';
        }

        return TellerEntry::CATEGORIES[$this->category]['label'] ?? Str::headline((string) $this->category);
    }

    public function basisLabel(): string
    {
        return $this->basis === self::BASIS_PERCENT ? 'Percentage' : 'Fixed amount';
    }

    public function scopeLabel(): string
    {
        return match ($this->applies_to) {
            self::APPLIES_CATEGORIES => 'Membership categories: '.collect($this->member_categories ?? [])
                ->map(fn ($category) => ucfirst((string) $category))
                ->implode(', '),
            self::APPLIES_MEMBERS => 'Selected members',
            default => 'All members',
        };
    }

    public function rateLabel(): string
    {
        $rate = rtrim(rtrim(number_format($this->rate_bps / 100, 2, '.', ''), '0'), '.');

        return $rate.'%';
    }

    public function summary(): string
    {
        $summary = $this->basis === self::BASIS_PERCENT
            ? $this->rateLabel().' of the amount'
            : Money::format($this->amount);

        if ($this->min_amount > 0) {
            $summary .= ' · min '.Money::format($this->min_amount);
        }

        if ($this->max_amount !== null) {
            $summary .= ' · max '.Money::format($this->max_amount);
        }

        return $summary;
    }

    public function appliesToMember(Member $member): bool
    {
        if ($this->applies_to === self::APPLIES_ALL) {
            return true;
        }

        if ($this->applies_to === self::APPLIES_CATEGORIES) {
            return in_array((string) $member->membership_category, $this->member_categories ?? [], true);
        }

        if ($this->applies_to === self::APPLIES_MEMBERS) {
            if ($this->relationLoaded('members')) {
                return $this->members->contains('id', $member->id);
            }

            return $this->members()->whereKey($member->id)->exists();
        }

        return false;
    }

    /**
     * The charge this rule produces for a transaction amount, before any
     * waiving decision is applied.
     */
    public function calculatedAmount(int $baseAmount): int
    {
        if ($baseAmount <= 0) {
            return 0;
        }

        $amount = $this->basis === self::BASIS_PERCENT
            ? (int) round($baseAmount * $this->rate_bps / 10000)
            : (int) $this->amount;

        if ($this->min_amount > 0) {
            $amount = max($amount, $this->min_amount);
        }

        if ($this->max_amount !== null) {
            $amount = min($amount, $this->max_amount);
        }

        return max(0, $amount);
    }
}
