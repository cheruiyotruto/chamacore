<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Loan extends Model
{
    use Auditable;
    use BelongsToGroup;
    use OwnedByMember;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GUARANTORS_PENDING = 'guarantors_pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DISBURSED = 'disbursed';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESTRUCTURED = 'restructured';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_DEFAULTED = 'defaulted';
    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'uuid', 'group_id', 'member_id', 'loan_product_id', 'loan_no', 'status',
        'category', 'requested_amount', 'approved_amount', 'principal',
        'total_interest', 'total_fees', 'total_repayable', 'outstanding_principal',
        'outstanding_interest', 'outstanding_fees', 'outstanding_penalties',
        'amount_paid', 'duration', 'duration_unit', 'repayment_frequency',
        'interest_rate_bps', 'interest_period', 'interest_method', 'accrual_mode',
        'day_count', 'installment_count', 'purpose', 'purpose_description',
        'multiplier_breakdown', 'terms_snapshot', 'metadata', 'submitted_at',
        'approved_at', 'approved_by', 'disbursed_at', 'disbursed_by',
        'first_due_date', 'maturity_date', 'closed_at', 'written_off_at',
        'restructured_from_id', 'created_by', 'employer_finance_required',
        'employer_finance_status', 'employer_finance_form_sent_at',
        'employer_finance_confirmed_at', 'employer_finance_officer_name',
        'employer_finance_reference', 'employer_finance_notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'integer',
            'approved_amount' => 'integer',
            'principal' => 'integer',
            'total_interest' => 'integer',
            'total_fees' => 'integer',
            'total_repayable' => 'integer',
            'outstanding_principal' => 'integer',
            'outstanding_interest' => 'integer',
            'outstanding_fees' => 'integer',
            'outstanding_penalties' => 'integer',
            'amount_paid' => 'integer',
            'interest_rate_bps' => 'integer',
            'duration' => 'integer',
            'installment_count' => 'integer',
            'multiplier_breakdown' => 'array',
            'terms_snapshot' => 'array',
            'metadata' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'closed_at' => 'datetime',
            'written_off_at' => 'datetime',
            'employer_finance_required' => 'boolean',
            'employer_finance_form_sent_at' => 'datetime',
            'employer_finance_confirmed_at' => 'datetime',
            'first_due_date' => 'date',
            'maturity_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Loan $loan) {
            $loan->uuid ??= (string) Str::uuid();
            $loan->loan_no ??= 'LN-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        });
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function product()
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }

    public function schedules()
    {
        return $this->hasMany(LoanSchedule::class)->orderBy('installment_no');
    }

    public function payments()
    {
        return $this->hasMany(LoanPayment::class)->orderByDesc('paid_at');
    }

    public function approvals()
    {
        return $this->hasMany(LoanApproval::class)->orderBy('step_index');
    }

    public function guarantors()
    {
        return $this->hasMany(Guarantor::class);
    }

    public function penaltyCharges()
    {
        return $this->hasMany(LoanPenaltyCharge::class);
    }

    public function restructures()
    {
        return $this->hasMany(LoanRestructure::class);
    }

    public function previousLoan()
    {
        return $this->belongsTo(Loan::class, 'restructured_from_id');
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function requiresEmployerFinance(): bool
    {
        return (bool) $this->employer_finance_required;
    }

    public function employerFinanceConfirmed(): bool
    {
        return $this->requiresEmployerFinance()
            && $this->employer_finance_status === \App\Support\EmployerFinance::STATUS_CONFIRMED;
    }

    public function employerFinanceStatusLabel(): string
    {
        return match ($this->employer_finance_status) {
            \App\Support\EmployerFinance::STATUS_PENDING_FORM => 'Awaiting stamped form',
            \App\Support\EmployerFinance::STATUS_FORM_UPLOADED => 'Form uploaded · awaiting finance office',
            \App\Support\EmployerFinance::STATUS_CONFIRMED => 'Confirmed by finance office',
            \App\Support\EmployerFinance::STATUS_DECLINED => 'Declined by finance office',
            default => 'Not required',
        };
    }

    public function outstandingTotal(): int
    {
        return $this->outstanding_principal
            + $this->outstanding_interest
            + $this->outstanding_fees
            + $this->outstanding_penalties;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_DISBURSED, self::STATUS_ACTIVE, self::STATUS_RESTRUCTURED,
        ], true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_CLOSED, self::STATUS_WRITTEN_OFF], true);
    }

    public function nextDueSchedule(): ?LoanSchedule
    {
        return $this->schedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->first();
    }

    public function isInArrears(): bool
    {
        return $this->schedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->exists();
    }

    public function overdueAmount(): int
    {
        return (int) $this->schedules()
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->get()
            ->sum(fn (LoanSchedule $schedule) => $schedule->totalDue() - $schedule->totalPaid());
    }

    public function progressPercent(): int
    {
        if ($this->total_repayable <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->amount_paid / $this->total_repayable) * 100));
    }

    public function statusLabel(): string
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE, self::STATUS_DISBURSED => 'badge-success',
            self::STATUS_APPROVED => 'badge-info',
            self::STATUS_REJECTED, self::STATUS_DEFAULTED, self::STATUS_WRITTEN_OFF => 'badge-danger',
            self::STATUS_CLOSED => 'badge-neutral',
            default => 'badge-warning',
        };
    }
}
