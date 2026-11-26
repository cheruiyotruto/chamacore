<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Approval ceilings in minor units. Null means no ceiling.
     */
    private const APPROVAL_LIMITS = [
        'chairperson' => 5_000_000,
        'treasurer' => 2_000_000,
        'loan_officer' => 1_000_000,
        'credit_committee' => 3_000_000,
    ];

    public function run(): void
    {
        Group::withoutGlobalScopes()
            ->orderBy('id')
            ->each(fn (Group $group) => $this->seedForGroup($group));
    }

    public function seedForGroup(Group $group): void
    {
        foreach (config('chama.roles', []) as $key => $name) {
            Role::withoutGlobalScopes()->updateOrCreate(
                ['group_id' => $group->id, 'key' => $key],
                [
                    'name' => $name,
                    'permissions' => config("chama.role_permissions.{$key}", []),
                    'approval_limit' => self::APPROVAL_LIMITS[$key] ?? null,
                    'is_system' => true,
                ]
            );
        }
    }
}
