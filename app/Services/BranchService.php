<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Group;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingsProduct;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates and maintains branch tenants under an organisation/group.
 *
 * A branch is a normal Group row with a parent link, so all existing financial
 * ledgers, wallets, memberships and permissions remain isolated per branch.
 */
class BranchService
{
    public function create(Group $parent, array $data, User $actor): Group
    {
        if ($parent->status !== 'active') {
            throw new BusinessRuleException('Only an active organisation or branch can add a branch.');
        }

        $owner = $parent->billingOwner();

        return DB::transaction(function () use ($parent, $data, $actor, $owner) {
            $branch = Group::withoutGlobalScopes()->create([
                'parent_group_id' => $parent->id,
                'is_branch' => true,
                'name' => $data['name'],
                'branch_code' => $this->normaliseCode($data['branch_code'] ?? null, $data['name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'county' => $data['county'] ?? null,
                'sub_county' => $data['sub_county'] ?? $parent->sub_county,
                'address' => $data['address'] ?? null,
                'description' => $data['description'] ?? null,
                'currency' => $parent->currency,
                'timezone' => $parent->timezone,
                'fiscal_year_start_month' => $parent->fiscal_year_start_month,
                'status' => 'active',
                'subscription_status' => $owner->subscription_status,
                'trial_ends_at' => $owner->trial_ends_at,
                'settings' => $parent->settings,
                'created_by' => $actor->id,
            ]);

            app(RoleSeeder::class)->seedForGroup($branch);
            app(LedgerService::class)->bootstrap($branch);
            $this->copyProducts($parent, $branch);
            $this->attachManager($branch, $data, $actor);

            AuditService::log('branch.created', [
                'branch_id' => $branch->id,
                'parent_group_id' => $parent->id,
                'name' => $branch->name,
                'branch_code' => $branch->branch_code,
            ], $parent);

            return $branch;
        });
    }

    public function update(Group $branch, array $data, User $actor): Group
    {
        if (! $branch->isBranch()) {
            throw new BusinessRuleException('Only branches can be edited here.');
        }

        $old = $branch->only(['name', 'branch_code', 'email', 'phone', 'county', 'address', 'status', 'description']);
        $branch->update(array_filter([
            'name' => $data['name'] ?? null,
            'branch_code' => isset($data['branch_code']) ? $this->normaliseCode($data['branch_code'], $data['name'] ?? $branch->name) : null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'county' => $data['county'] ?? null,
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($value) => $value !== null));

        AuditService::record('branch.updated', $branch, $old, $branch->only(array_keys($old)), $branch->id);

        return $branch->refresh();
    }

    public function toggleStatus(Group $branch, User $actor): Group
    {
        if (! $branch->isBranch()) {
            throw new BusinessRuleException('Only branches can be enabled or disabled here.');
        }

        $branch->update(['status' => $branch->status === 'active' ? 'suspended' : 'active']);

        AuditService::log('branch.status_changed', [
            'branch_id' => $branch->id,
            'status' => $branch->status,
        ], $branch->parent);

        return $branch->refresh();
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return [
            $parts[0] ?? 'Branch',
            implode(' ', array_slice($parts, 1)) ?: 'Manager',
        ];
    }

    private function normaliseCode(?string $code, string $name): string
    {
        if ($code) {
            return strtoupper(preg_replace('/[^A-Za-z0-9-]+/', '-', $code) ?: 'BRANCH');
        }

        return strtoupper(Str::limit(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?: 'BRANCH', 30, ''));
    }

    private function copyProducts(Group $parent, Group $branch): void
    {
        foreach (SavingsProduct::withoutGlobalScopes()->where('group_id', $parent->id)->get() as $product) {
            $copy = $product->replicate();
            $copy->group_id = $branch->id;
            $copy->save();
        }

        foreach (LoanProduct::withoutGlobalScopes()->where('group_id', $parent->id)->get() as $product) {
            $copy = $product->replicate();
            $copy->group_id = $branch->id;
            $copy->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function attachManager(Group $branch, array $data, User $actor): void
    {
        $manager = $actor;
        $createdAccount = false;

        if (! empty($data['manager_email'])) {
            $manager = User::where('email', $data['manager_email'])->first();

            if (! $manager) {
                $manager = User::create([
                    'name' => $data['manager_name'] ?: $data['name'].' Manager',
                    'email' => $data['manager_email'],
                    'phone' => $data['manager_phone'] ?? null,
                    'password' => Hash::make(Str::random(40)),
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]);
                $createdAccount = true;
            }
        }

        $roleKey = $data['manager_role'] ?? 'group_admin';
        $role = Role::withoutGlobalScopes()
            ->where('group_id', $branch->id)
            ->where('key', $roleKey)
            ->where('key', '!=', 'member')
            ->firstOrFail();

        $managerName = $data['manager_name'] ?? $manager->name;
        [$firstName, $lastName] = $this->splitName($managerName);

        Member::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $branch->id, 'user_id' => $manager->id],
            [
                'role_id' => $role->id,
                'member_no' => 'B'.($branch->branch_code ?: $branch->id).'-001',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $manager->email,
                'phone' => $data['manager_phone'] ?? $manager->phone,
                'membership_category' => 'staff',
                'status' => 'active',
                'kyc_status' => 'pending',
                'joined_at' => now(),
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]
        );

        app(SavingsService::class)->openDefaultAccounts(
            Member::withoutGlobalScopes()->where('group_id', $branch->id)->where('user_id', $manager->id)->firstOrFail()
        );
        app(WalletService::class)->ensureWallet(
            Member::withoutGlobalScopes()->where('group_id', $branch->id)->where('user_id', $manager->id)->firstOrFail()
        );

        if ($createdAccount) {
            try {
                Password::sendResetLink(['email' => $manager->email]);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
