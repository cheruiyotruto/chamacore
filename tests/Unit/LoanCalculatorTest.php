<?php

namespace Tests\Unit;

use App\Services\Loans\LoanCalculator;
use Tests\TestCase;

class LoanCalculatorTest extends TestCase
{
    private LoanCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new LoanCalculator;
    }

    public function test_reducing_annuity_schedule_returns_the_exact_principal(): void
    {
        $quote = $this->calculator->quote([
            'principal' => 1_000_000,
            'rate_bps' => 1200,
            'interest_period' => LoanCalculator::PERIOD_MONTHLY,
            'method' => LoanCalculator::METHOD_REDUCING_ANNUITY,
            'repayment_frequency' => LoanCalculator::FREQUENCY_MONTHLY,
            'duration' => 12,
            'duration_unit' => 'months',
            'start_date' => '2026-01-01',
        ]);

        $this->assertCount(12, $quote->lines);
        $this->assertSame(1_000_000, array_sum(array_map(
            fn ($line) => $line->principalDue,
            $quote->lines
        )));
        $this->assertSame(
            $quote->principal + $quote->totalInterest + $quote->totalFees,
            $quote->totalRepayable
        );
        $this->assertGreaterThan(0, $quote->totalInterest);
        $this->assertSame(0, $quote->lines[11]->outstandingPrincipal);
    }

    public function test_flat_interest_schedule_is_complete_and_balanced(): void
    {
        $quote = $this->calculator->quote([
            'principal' => 2_000_000,
            'rate_bps' => 500,
            'interest_period' => LoanCalculator::PERIOD_MONTHLY,
            'method' => LoanCalculator::METHOD_FLAT,
            'repayment_frequency' => LoanCalculator::FREQUENCY_MONTHLY,
            'duration' => 6,
            'duration_unit' => 'months',
            'start_date' => '2026-01-01',
        ]);

        $this->assertCount(6, $quote->lines);
        $this->assertSame(2_000_000, array_sum(array_map(
            fn ($line) => $line->principalDue,
            $quote->lines
        )));
        $this->assertSame(595_068, $quote->totalInterest);
        $this->assertSame(2_595_068, $quote->totalRepayable);
        $this->assertSame(
            array_sum(array_map(fn ($line) => $line->totalDue, $quote->lines)),
            $quote->totalRepayable
        );
    }

    public function test_reducing_annuity_interest_is_charged_on_the_declining_balance(): void
    {
        $quote = $this->calculator->quote([
            'principal' => 1_000_000,
            'rate_bps' => 1200,
            'interest_period' => LoanCalculator::PERIOD_MONTHLY,
            'method' => LoanCalculator::METHOD_REDUCING_ANNUITY,
            'repayment_frequency' => LoanCalculator::FREQUENCY_MONTHLY,
            'duration' => 12,
            'duration_unit' => 'months',
            'start_date' => '2026-01-01',
        ]);

        $this->assertSame(933_553, $quote->totalInterest);
        $this->assertSame(1_933_553, $quote->totalRepayable);
        $this->assertGreaterThan(
            $quote->lines[11]->interestDue,
            $quote->lines[0]->interestDue,
            'Interest should decline as the outstanding principal is repaid.'
        );
        $this->assertSame(0, $quote->lines[11]->outstandingPrincipal);
    }

    public function test_reducing_equal_principal_schedule_is_complete_and_balanced(): void
    {
        $quote = $this->calculator->quote([
            'principal' => 900_000,
            'rate_bps' => 1200,
            'interest_period' => LoanCalculator::PERIOD_MONTHLY,
            'method' => LoanCalculator::METHOD_REDUCING_EQUAL_PRINCIPAL,
            'repayment_frequency' => LoanCalculator::FREQUENCY_MONTHLY,
            'duration' => 9,
            'duration_unit' => 'months',
            'start_date' => '2026-01-01',
        ]);

        $this->assertCount(9, $quote->lines);
        $this->assertSame(900_000, array_sum(array_map(
            fn ($line) => $line->principalDue,
            $quote->lines
        )));
        $this->assertSame(536_392, $quote->totalInterest);
        $this->assertSame(1_436_392, $quote->totalRepayable);
        $this->assertGreaterThan(
            $quote->lines[8]->interestDue,
            $quote->lines[0]->interestDue
        );
    }

    public function test_zero_rate_loan_has_no_interest(): void
    {
        $quote = $this->calculator->quote([
            'principal' => 900_000,
            'rate_bps' => 0,
            'interest_period' => LoanCalculator::PERIOD_MONTHLY,
            'method' => LoanCalculator::METHOD_REDUCING_EQUAL_PRINCIPAL,
            'repayment_frequency' => LoanCalculator::FREQUENCY_MONTHLY,
            'duration' => 9,
            'duration_unit' => 'months',
            'start_date' => '2026-01-01',
        ]);

        $this->assertSame(0, $quote->totalInterest);
        $this->assertSame(900_000, $quote->totalRepayable);
    }
}
