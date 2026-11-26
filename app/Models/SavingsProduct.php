<?php

namespace App\Models;

use App\Models\Concerns\BelongsToGroup;
use Illuminate\Database\Eloquent\Model;

class SavingsProduct extends Model
{
    use BelongsToGroup;

    public const TYPE_MANDATORY = 'mandatory';
    public const TYPE_VOLUNTARY = 'voluntary';
    public const TYPE_EMERGENCY = 'emergency';
    public const TYPE_SHARES = 'shares';
    public const TYPE_WELFARE = 'welfare';
    public const TYPE_SPECIAL = 'special';

    protected $fillable = [
        'group_id', 'name', 'code', 'type', 'description', 'interest_rate_bps',
        'interest_period', 'min_balance', 'contribution_amount',
        'contribution_frequency', 'allows_withdrawal', 'qualifies_for_loan',
        'share_weight_bps', 'is_mandatory', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allows_withdrawal' => 'boolean',
            'qualifies_for_loan' => 'boolean',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
            'interest_rate_bps' => 'integer',
            'min_balance' => 'integer',
            'contribution_amount' => 'integer',
            'share_weight_bps' => 'integer',
        ];
    }

    public function accounts()
    {
        return $this->hasMany(SavingsAccount::class);
    }
}
