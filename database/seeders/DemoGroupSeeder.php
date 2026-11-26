<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Models\MerryGoRoundCycle;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsProduct;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerService;
use App\Services\MerryGoRoundService;
use App\Services\SavingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A fully working demonstration tenant. It is intentionally opt-in through
 * CHAMA_SEED_DEMO and must never be run against production data.
 */
class DemoGroupSeeder extends Seeder
{
    private const MEMBERS = [
        ['Grace', 'Wanjiku', 'group_admin'],
        ['Peter', 'Otieno', 'chairperson'],
        ['Mary', 'Achieng', 'treasurer'],
        ['James', 'Mwangi', 'secretary'],
        ['Esther', 'Njeri', 'loan_officer'],
        ['David', 'Kiptoo', 'member'],
        ['Faith', 'Wambui', 'member'],
        ['Samuel', 'Ochieng', 'member'],
        ['Lucy', 'Akinyi', 'member'],
        ['Joseph', 'Mutua', 'member'],
        ['Anne', 'Chebet', 'member'],
        ['Brian', 'Omondi', 'member'],
    ];

    public function run(): void
    {
        $admin = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'admin@umojademo.test'],
            [
                'name' => 'Grace Wanjiku',
                'phone' => '+254700000001',
                'password' => Hash::make('Password!2026'),
                'email_verified_at' => now(),
                'status' => 'active',
            ]
        );

        $group = Group::withoutGlobalScopes()->firstOrCreate(
            ['registration_no' => 'CHAMA-DEMO-001'],
            [
                'name' => 'Umoja Savings Group',
                'slug' => 'umoja-savings-group',
                'email' => 'info@umojademo.test',
                'phone' => '+254700000000',
                'county' => 'Nairobi',
                'sub_county' => 'Westlands',
                'address' => 'Plot 12, Waiyaki Way, Nairobi',
                'currency' => 'KES',
                'timezone' => 'Africa/Nairobi',
                'status' => 'active',
                'subscription_status' => 'active',
                'trial_ends_at' => null,
                'fiscal_year_start_month' => 1,
                'description' => 'Demonstration table-banking group seeded by Chama Core.',
                'created_by' => $admin->id,
                'settings' => [
                    'branding' => ['primary_colour' => '#166534'],
                    'notifications' => ['sms_enabled' => true, 'email_enabled' => true],
                ],
            ]
        );

        $group->update(['created_by' => $group->created_by ?: $admin->id]);

        app(RoleSeeder::class)->seedForGroup($group);
        $roles = Role::withoutGlobalScopes()->where('group_id', $group->id)->get()->keyBy('key');

        $members = $this->members($group, $roles, $admin);

        $products = $this->savingsProducts($group);
        $this->loanProducts($group);

        app(LedgerService::class)->bootstrap($group);

        $savings = app(SavingsService::class);

        foreach ($members as $member) {
            Wallet::withoutGlobalScopes()->firstOrCreate(
                ['group_id' => $group->id, 'member_id' => $member->id],
                ['balance' => 0, 'available_balance' => 0, 'status' => 'active']
            );

            foreach ($products as $product) {
                $account = $savings->openAccount($member, $product);
                $this->fundAccount($savings, $member, $account, $product);
            }
        }

        $this->merryGoRound($group, $members, $admin);
        $this->subscription($group);
        $this->settings($group);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Role>  $roles
     * @return array<int, Member>
     */
    private function members(Group $group, $roles, User $admin): array
    {
        $members = [];

        foreach (self::MEMBERS as $index => [$firstName, $lastName, $roleKey]) {
            $number = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $email = $index === 0 ? $admin->email : 'member'.($index + 1).'@umojademo.test';
            $phone = '+2547'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT);

            $user = $index === 0
                ? $admin
                : User::withoutGlobalScopes()->firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => "{$firstName} {$lastName}",
                        'phone' => $phone,
                        'password' => Hash::make('Password!2026'),
                        'email_verified_at' => now(),
                        'status' => 'active',
                    ]
                );

            $member = Member::withoutGlobalScopes()->updateOrCreate(
                ['group_id' => $group->id, 'user_id' => $user->id],
                [
                    'role_id' => $roles[$roleKey]?->id,
                    'member_no' => 'UJG-'.$number,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'national_id' => '10'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT),
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'gender' => in_array($firstName, ['Grace', 'Mary', 'Esther', 'Faith', 'Lucy', 'Anne'], true) ? 'female' : 'male',
                    'date_of_birth' => now()->subYears(25 + $index)->subDays($index)->toDateString(),
                    'address' => 'Nairobi, Kenya',
                    'occupation' => 'Business owner',
                    'membership_category' => 'ordinary',
                    'status' => 'active',
                    'kyc_status' => 'verified',
                    'joined_at' => now()->subMonths(12 - min(11, $index)),
                    'approved_by' => $admin->id,
                    'approved_at' => now()->subMonths(12 - min(11, $index)),
                    'membership_fee_paid' => 100000,
                    'metadata' => ['seeded' => true],
                ]
            );

            $members[] = $member;
        }

        return $members;
    }

    /**
     * @return array<int, SavingsProduct>
     */
    private function savingsProducts(Group $group): array
    {
        $definitions = [
            [
                'code' => 'MAIN',
                'name' => 'Main savings',
                'type' => SavingsProduct::TYPE_MANDATORY,
                'description' => 'Weekly member savings used for loan qualification.',
                'interest_rate_bps' => 300,
                'interest_period' => 'annual',
                'min_balance' => 100000,
                'contribution_amount' => 50000,
                'contribution_frequency' => 'weekly',
                'allows_withdrawal' => true,
                'qualifies_for_loan' => true,
                'is_mandatory' => true,
            ],
            [
                'code' => 'SHARES',
                'name' => 'Share capital',
                'type' => SavingsProduct::TYPE_SHARES,
                'description' => 'Member share capital; withdrawn only on exit.',
                'interest_rate_bps' => 0,
                'interest_period' => 'annual',
                'min_balance' => 0,
                'allows_withdrawal' => false,
                'qualifies_for_loan' => true,
                'share_weight_bps' => 10000,
                'is_mandatory' => false,
            ],
            [
                'code' => 'WELFARE',
                'name' => 'Welfare fund',
                'type' => SavingsProduct::TYPE_WELFARE,
                'description' => 'Contributions for bereavement and emergency support.',
                'interest_rate_bps' => 0,
                'interest_period' => 'annual',
                'min_balance' => 0,
                'contribution_amount' => 10000,
                'contribution_frequency' => 'monthly',
                'allows_withdrawal' => false,
                'qualifies_for_loan' => false,
                'is_mandatory' => true,
            ],
        ];

        $products = [];

        foreach ($definitions as $definition) {
            $products[] = SavingsProduct::withoutGlobalScopes()->updateOrCreate(
                ['group_id' => $group->id, 'code' => $definition['code']],
                $definition + ['is_active' => true]
            );
        }

        return $products;
    }

    private function loanProducts(Group $group): void
    {
        $definitions = [
            [
                'code' => 'EMERGENCY',
                'name' => 'Emergency loan',
                'category' => 'emergency',
                'description' => 'Short-term loan for urgent household needs.',
                'interest_method' => 'reducing_annuity',
                'interest_rate_bps' => 1000,
                'interest_period' => 'monthly',
                'repayment_frequency' => 'monthly',
                'duration_min' => 1,
                'duration_max' => 12,
                'duration_unit' => 'months',
                'min_amount' => 100000,
                'max_amount' => 2000000,
                'multiplier_bps' => 20000,
                'guarantee_multiplier_bps' => 30000,
                'requires_guarantors' => true,
                'min_guarantors' => 2,
                'guarantor_coverage_bps' => 10000,
                'penalty_rate_bps' => 200,
                'penalty_period' => 'daily',
                'penalty_grace_days' => 3,
                'processing_fee_bps' => 100,
                'max_concurrent_loans' => 1,
            ],
            [
                'code' => 'DEVELOPMENT',
                'name' => 'Development loan',
                'category' => 'business',
                'description' => 'Larger loan for business stock or equipment.',
                'interest_method' => 'reducing_annuity',
                'interest_rate_bps' => 1200,
                'interest_period' => 'monthly',
                'repayment_frequency' => 'monthly',
                'duration_min' => 3,
                'duration_max' => 24,
                'duration_unit' => 'months',
                'min_amount' => 500000,
                'max_amount' => 5000000,
                'multiplier_bps' => 30000,
                'guarantee_multiplier_bps' => 30000,
                'requires_guarantors' => true,
                'min_guarantors' => 2,
                'guarantor_coverage_bps' => 10000,
                'penalty_rate_bps' => 200,
                'penalty_period' => 'daily',
                'penalty_grace_days' => 3,
                'processing_fee_bps' => 150,
                'max_concurrent_loans' => 1,
            ],
        ];

        foreach ($definitions as $definition) {
            LoanProduct::withoutGlobalScopes()->updateOrCreate(
                ['group_id' => $group->id, 'code' => $definition['code']],
                $definition + ['is_active' => true]
            );
        }
    }

    private function fundAccount(
        SavingsService $savings,
        Member $member,
        SavingsAccount $account,
        SavingsProduct $product
    ): void {
        if ($account->balance > 0) {
            return;
        }

        $amount = match ($product->type) {
            SavingsProduct::TYPE_SHARES => 200000,
            SavingsProduct::TYPE_WELFARE => 50000,
            default => 500000,
        };

        $savings->deposit(
            $member,
            $account,
            $amount,
            'contribution',
            LedgerService::BANK,
            'DEMO-'.$member->member_no.'-'.$product->code,
            'DEMO-'.$member->id.'-'.$product->id,
            'Demonstration opening balance'
        );
    }

    /**
     * @param  array<int, Member>  $members
     */
    private function merryGoRound(Group $group, array $members, User $actor): void
    {
        $exists = MerryGoRoundCycle::withoutGlobalScopes()
            ->where('group_id', $group->id)
            ->where('name', 'Demo Merry-Go-Round')
            ->exists();

        if ($exists) {
            return;
        }

        app(MerryGoRoundService::class)->createCycle($group, [
            'name' => 'Demo Merry-Go-Round',
            'contribution_amount' => 100000,
            'frequency' => 'monthly',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->addMonthsNoOverflow(11)->toDateString(),
            'rotation_type' => 'fixed',
            'penalty_rate_bps' => 200,
            'penalty_grace_days' => 3,
            'member_ids' => array_map(fn (Member $member) => $member->id, $members),
        ], $actor);
    }

    private function subscription(Group $group): void
    {
        $plan = Plan::where('slug', 'growth')->first();

        if (! $plan) {
            return;
        }

        Subscription::withoutGlobalScopes()->firstOrCreate(
            ['group_id' => $group->id, 'plan_id' => $plan->id, 'status' => 'active'],
            [
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
                'auto_renew' => true,
            ]
        );
    }

    private function settings(Group $group): void
    {
        $definitions = [
            'finance.penalty_rate_bps' => 200,
            'finance.penalty_grace_days' => 3,
            'mgr.reminder_days' => 3,
            'loans.minimum_guarantors' => 2,
            'notifications.sms_enabled' => true,
        ];

        foreach ($definitions as $key => $value) {
            GroupSetting::withoutGlobalScopes()->updateOrCreate(
                ['group_id' => $group->id, 'key' => $key],
                ['value' => $value]
            );
        }
    }
}
