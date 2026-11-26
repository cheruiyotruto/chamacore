<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\Member;

/**
 * Group-level switch for members buying M-Pesa float from their wallet.
 *
 * The feature is opt-out: groups that have never saved the setting may use it
 * immediately, while a stored false value hides the portal section and blocks
 * direct access. Staff fulfilment is available to treasurers/accountants
 * regardless of this member-facing switch.
 */
final class FloatAccess
{
    public const SETTING_KEY = 'allow_member_float_purchases';

    public static function enabled(?Group $group): bool
    {
        if (! $group) {
            return false;
        }

        $setting = GroupSetting::withoutGlobalScopes()
            ->forGroup($group->id)
            ->where('key', self::SETTING_KEY)
            ->first();

        return $setting ? (bool) $setting->value : true;
    }

    public static function memberAccessEnabled(Group $group, Member $member): bool
    {
        return $member->status === 'active' && self::enabled($group);
    }
}
