<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToGroup;
use App\Models\Concerns\OwnedByMember;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Member extends \Illuminate\Database\Eloquent\Model
{
    use Auditable;
    use BelongsToGroup;
    use HasFactory;
    use OwnedByMember;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'group_id', 'user_id', 'role_id', 'member_no', 'first_name',
        'middle_name', 'last_name', 'national_id', 'phone', 'email', 'gender',
        'date_of_birth', 'address', 'occupation', 'employer', 'employer_address', 'workstation',
        'membership_category', 'status', 'can_bank_transfer', 'portal_sections', 'kyc_status', 'joined_at', 'approved_by',
        'approved_at', 'rejection_reason', 'photo_path', 'id_document_path',
        'membership_fee_paid', 'metadata', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'approved_at' => 'datetime',
            'date_of_birth' => 'date',
            'can_bank_transfer' => 'boolean',
            'membership_fee_paid' => 'integer',
            'metadata' => 'array',
            'portal_sections' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Member $member) {
            $member->uuid ??= (string) Str::uuid();
        });
    }

    public function memberScopeColumn(): string
    {
        return 'id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function nextOfKin(): HasMany
    {
        return $this->hasMany(NextOfKin::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function activeLoans(): HasMany
    {
        return $this->loans()->whereIn('status', ['disbursed', 'active', 'restructured']);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function guarantees(): HasMany
    {
        return $this->hasMany(Guarantor::class, 'guarantor_member_id');
    }

    public function mgrParticipations(): HasMany
    {
        return $this->hasMany(MerryGoRoundParticipant::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isStaff(): bool
    {
        return $this->role && $this->role->key !== 'member';
    }

    public function biometricRequired(): bool
    {
        return (bool) data_get($this->metadata ?? [], 'biometric_required', false);
    }

    public function hasBiometricCapture(): bool
    {
        return $this->documents()
            ->where('type', 'like', 'biometric%')
            ->where('status', '!=', 'rejected')
            ->exists();
    }

    public function setBiometricRequired(bool $required): self
    {
        $metadata = $this->metadata ?? [];

        if ($required) {
            $metadata['biometric_required'] = true;
        } else {
            unset($metadata['biometric_required']);
        }

        $this->metadata = $metadata;

        return $this;
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['applied', 'approved']);
    }
}
