<?php

namespace App\Support;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\PlatformSetting;

/**
 * Controls whether members may initiate bank transfers from their wallets.
 *
 * Staff with payouts.manage are never blocked by these switches: they settle
 * bill collections on behalf of the group. Members need both the group switch
 * and their own per-member opt-in.
 */
final class PayoutAccess
{
    public const KEY = 'payout_access';

    public static function platformEnabled(): bool
    {
        $value = PlatformSetting::query()->where('key', self::KEY)->first()?->value;

        return (bool) (is_array($value) ? ($value['members_enabled'] ?? false) : false);
    }

    public static function groupEnabled(Group $group): bool
    {
        $value = GroupSetting::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('key', self::KEY)
            ->first()?->value;

        if (is_array($value) && array_key_exists('members_enabled', $value)) {
            return (bool) $value['members_enabled'];
        }

        return self::platformEnabled();
    }

    /**
     * @return array<string, bool>
     */
    public static function normalize(array $input): array
    {
        return ['members_enabled' => (bool) ($input['members_enabled'] ?? false)];
    }
}
