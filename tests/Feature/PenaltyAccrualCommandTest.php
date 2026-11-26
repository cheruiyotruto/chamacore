<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Loan;
use App\Models\LoanPenaltyCharge;
use App\Models\LoanProduct;
use App\Models\LoanSchedule;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenaltyAccrualCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accrues_penalties_only_once_per_day(): void
    {
        $group = Group::factory()->create();

        $product = LoanProduct::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'name' => 'Test loan',
            'code' => 'TEST',
            'interest_method' => 'reducing_annuity',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'repayment_frequency' => 'monthly',
            'duration_min' => 1,
            'duration_max' => 12,
            'duration_unit' => 'months',
            'min_amount' => 0,
            'max_amount' => 5_000_000,
            'penalty_rate_bps' => 200,
            'penalty_period' => 'daily',
            'penalty_grace_days' => 3,
            'is_active' => true,
        ]);

        $member = Member::factory()->forGroup($group)->create();

        $loan = Loan::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'status' => Loan::STATUS_ACTIVE,
            'requested_amount' => 1_000_000,
            'approved_amount' => 1_000_000,
            'principal' => 1_000_000,
            'total_interest' => 100_000,
            'total_repayable' => 1_100_000,
            'outstanding_principal' => 1_000_000,
            'outstanding_interest' => 100_000,
            'duration' => 12,
            'duration_unit' => 'months',
            'repayment_frequency' => 'monthly',
            'interest_rate_bps' => 1200,
            'interest_period' => 'monthly',
            'interest_method' => 'reducing_annuity',
            'accrual_mode' => 'effective',
            'day_count' => 'actual_365',
            'disbursed_at' => now()->subDays(40),
        ]);

        LoanSchedule::withoutGlobalScopes()->create([
            'group_id' => $group->id,
            'loan_id' => $loan->id,
            'installment_no' => 1,
            'due_date' => now()->subDays(10)->toDateString(),
            'principal_due' => 1_000_000,
            'interest_due' => 100_000,
            'total_due' => 1_100_000,
            'status' => 'overdue',
        ]);

        $this->artisan('chama:accrue-penalties', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $firstRun = LoanPenaltyCharge::withoutGlobalScopes()->where('loan_id', $loan->id)->count();

        $this->assertGreaterThan(0, $firstRun);
        $this->assertGreaterThan(0, $loan->refresh()->outstanding_penalties);

        $this->artisan('chama:accrue-penalties', ['--date' => now()->toDateString()])
            ->assertSuccessful();

        $this->assertSame(
            $firstRun,
            LoanPenaltyCharge::withoutGlobalScopes()->where('loan_id', $loan->id)->count()
        );
    }
}
