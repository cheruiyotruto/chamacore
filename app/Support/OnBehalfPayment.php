<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;

/**
 * Group-level switch for members paying another member's savings, loans,
 * bills, merry-go-round contributions or wallet.
 */
class OnBehalfPayment
{
    public const SETTING_KEY = 'allow_member_on_behalf_payments';

    public const TYPES = [
        'savings' => 'Savings deposit',
        'loan' => 'Loan repayment',
        'bill' => 'Bill payment',
        'mgr' => 'Merry-go-round contribution',
        'wallet' => 'Wallet credit',
    ];

    public static function enabled(?Group $group): bool
    {
        if (! $group) {
            return false;
        }

        $setting = GroupSetting::withoutGlobalScopes()
            ->forGroup($group->id)
            ->where('key', self::SETTING_KEY)
            ->first();

        return $setting?->value === true;
    }
}
