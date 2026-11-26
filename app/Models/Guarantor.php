<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class Guarantor extends Model
{
    use BelongsToGroup;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CALLED = 'called';
    public const STATUS_INVITED = 'invited';
    public const STATUS_AWAITING_SAVINGS = 'awaiting_savings';

    /**
     * Statuses where the invited person has not yet become a responding
     * guarantor. These must block loan approval just like a pending request.
     *
     * @var array<int, string>
     */
    public const UNFULFILLED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_INVITED,
        self::STATUS_AWAITING_SAVINGS,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'group_id', 'loan_id', 'guarantor_member_id', 'invitation_id',
        'invitee_email', 'invitee_phone', 'guaranteed_amount',
        'required_savings_amount', 'principal_amount', 'full_obligation_amount',
        'coverage_basis', 'status', 'capacity_at_request', 'requested_at',
        'invitation_sent_at', 'funded_at', 'responded_at', 'released_at',
        'response_note',
    ];

    protected function casts(): array
    {
        return [
            'guaranteed_amount' => 'integer',
            'required_savings_amount' => 'integer',
            'principal_amount' => 'integer',
            'full_obligation_amount' => 'integer',
            'capacity_at_request' => 'integer',
            'requested_at' => 'datetime',
            'invitation_sent_at' => 'datetime',
            'funded_at' => 'datetime',
            'responded_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function guarantor()
    {
        return $this->belongsTo(Member::class, 'guarantor_member_id');
    }

    public function invitation()
    {
        return $this->belongsTo(MemberInvitation::class, 'invitation_id');
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function signatures()
    {
        return $this->documents()->where('type', 'guarantee_signature');
    }

    public function inviteeLabel(): string
    {
        return $this->guarantor?->full_name
            ?: $this->invitee_email
            ?: $this->invitee_phone
            ?: 'Invited guarantor';
    }
}
