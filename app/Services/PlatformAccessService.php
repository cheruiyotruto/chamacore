<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Manages the platform's oversight/full-control mode for selected tenants.
 *
 * Marked groups continue to run their own day-to-day operations. This service
 * only changes platform visibility/access; it deliberately does not rewrite
 * group ledger postings. Platform reporting derives revenue from those books.
 */
class PlatformAccessService
{
    public function setMode(Group $group, string $mode, User $actor): Group
    {
        if (! in_array($mode, array_keys(Group::PLATFORM_MODES), true)) {
            throw new BusinessRuleException('Unsupported platform management mode.');
        }

        $old = $group->only([
            'platform_management_mode',
            'platform_managed_at',
            'platform_managed_by',
        ]);

        $group->update([
            'platform_management_mode' => $mode,
            'platform_managed_at' => $mode === Group::PLATFORM_MODE_SELF ? null : now(),
            'platform_managed_by' => $mode === Group::PLATFORM_MODE_SELF ? null : $actor->id,
        ]);

        AuditService::record('platform.group.management_mode.changed', $group, $old, [
            'platform_management_mode' => $mode,
            'platform_managed_at' => $group->platform_managed_at?->toIso8601String(),
            'platform_managed_by' => $group->platform_managed_by,
        ], $group->id);

        return $group->refresh();
    }

    /**
     * Give the acting super admin a real group_admin membership so the existing
     * permission engine, membership scope and audit trail all remain intact.
     */
    public function ensureFullControlMembership(Group $group, User $admin): Member
    {
        if (! $admin->isSuperAdmin()) {
            throw new BusinessRuleException('Only platform administrators can use full control access.');
        }

        if (! $group->allowsFullPlatformControl()) {
            throw new BusinessRuleException('Full platform control is not enabled for this group.');
        }

        return DB::transaction(function () use ($group, $admin) {
            app(RoleSeeder::class)->seedForGroup($group);
            app(LedgerService::class)->bootstrap($group);

            $role = Role::withoutGlobalScopes()
                ->where('group_id', $group->id)
                ->where('key', 'group_admin')
                ->firstOrFail();

            [$firstName, $lastName] = $this->splitName($admin->name);

            $member = Member::withoutGlobalScopes()->firstOrCreate(
                ['group_id' => $group->id, 'user_id' => $admin->id],
                [
                    'role_id' => $role->id,
                    'member_no' => 'PLAT-'.str_pad((string) $admin->id, 5, '0', STR_PAD_LEFT),
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $admin->email,
                    'phone' => $admin->phone,
                    'membership_category' => 'staff',
                    'status' => 'active',
                    'kyc_status' => 'verified',
                    'joined_at' => now(),
                    'approved_by' => $admin->id,
                    'approved_at' => now(),
                    'notes' => 'Platform administrator full-control membership.',
                ]
            );

            $member->update([
                'role_id' => $role->id,
                'status' => 'active',
                'kyc_status' => 'verified',
            ]);

            app(WalletService::class)->ensureWallet($member);
            app(SavingsService::class)->openDefaultAccounts($member);

            AuditService::log('platform.group.full_control.membership', [
                'group_id' => $group->id,
                'member_id' => $member->id,
                'administrator_id' => $admin->id,
            ], $group);

            return $member->load('role', 'group');
        });
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return [
            $parts[0] ?? 'Platform',
            implode(' ', array_slice($parts, 1)) ?: 'Administrator',
        ];
    }
}
