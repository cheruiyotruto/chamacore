<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class TellerEntry extends Model
{
    use Auditable;
    use BelongsToGroup;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_BOUNCED = 'bounced';

    public const METHOD_CHEQUE = 'cheque';

    /**
     * Every teller desk operation, the direction cash moves and the permission
     * the existing cash module already relies on.
     */
    public const CATEGORIES = [
        'savings_deposit' => [
            'label' => 'Savings deposit',
            'direction' => self::DIRECTION_IN,
            'needs' => 'savings_account_id',
        ],
        'savings_withdrawal' => [
            'label' => 'Savings withdrawal',
            'direction' => self::DIRECTION_OUT,
            'needs' => 'savings_account_id',
        ],
        'loan_repayment' => [
            'label' => 'Loan repayment',
            'direction' => self::DIRECTION_IN,
            'needs' => 'loan_id',
        ],
        'mgr_contribution' => [
            'label' => 'Merry-go-round contribution',
            'direction' => self::DIRECTION_IN,
            'needs' => 'contribution_id',
        ],
        'investment_place' => [
            'label' => 'Investment placement',
            'direction' => self::DIRECTION_IN,
            'needs' => 'investment_product_id',
        ],
        'investment_payout' => [
            'label' => 'Investment payout',
            'direction' => self::DIRECTION_OUT,
            'needs' => 'investment_id',
        ],
        'wallet_deposit' => [
            'label' => 'Wallet top-up',
            'direction' => self::DIRECTION_IN,
            'needs' => null,
        ],
        'wallet_withdrawal' => [
            'label' => 'Wallet withdrawal',
            'direction' => self::DIRECTION_OUT,
            'needs' => null,
        ],
    ];

    protected $fillable = [
        'uuid', 'group_id', 'teller_session_id', 'member_id', 'receipt_no',
        'category', 'direction', 'amount', 'charge_amount', 'waived_charge_amount',
        'charge_breakdown', 'method', 'status', 'reference', 'narration',
        'cheque_details', 'payload', 'cleared_at', 'bounced_at', 'cleared_by',
        'bounced_by', 'clearance_notes',
        'source_type', 'source_id', 'journal_entry_id', 'charge_journal_entry_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'charge_amount' => 'integer',
            'waived_charge_amount' => 'integer',
            'charge_breakdown' => 'array',
            'cheque_details' => 'array',
            'payload' => 'array',
            'cleared_at' => 'datetime',
            'bounced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (TellerEntry $entry) => $entry->uuid ??= (string) Str::uuid());
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TellerSession::class, 'teller_session_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function chargeJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'charge_journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function bouncer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bounced_by');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category]['label'] ?? Str::headline((string) $this->category);
    }

    public function directionLabel(): string
    {
        return $this->direction === self::DIRECTION_IN ? 'Cash in' : 'Cash out';
    }

    public function isCash(): bool
    {
        return $this->method === 'cash';
    }

    public function isCheque(): bool
    {
        return $this->method === self::METHOD_CHEQUE;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCleared(): bool
    {
        return $this->status === self::STATUS_CLEARED;
    }

    public function isBounced(): bool
    {
        return $this->status === self::STATUS_BOUNCED;
    }

    public function canResolveCheque(): bool
    {
        return $this->isCheque() && $this->isPending();
    }

    public function statusLabel(): string
    {
        return Str::headline((string) $this->status);
    }

    public function signedAmount(): int
    {
        return $this->direction === self::DIRECTION_IN ? (int) $this->amount : -1 * (int) $this->amount;
    }

    /**
     * The cash effect of this transaction once teller charges are included.
     */
    public function cashMovement(): int
    {
        return $this->direction === self::DIRECTION_IN
            ? (int) $this->amount + (int) $this->charge_amount
            : (int) $this->amount - (int) $this->charge_amount;
    }

    public static function directionFor(string $category): string
    {
        return self::CATEGORIES[$category]['direction'] ?? self::DIRECTION_IN;
    }
}
