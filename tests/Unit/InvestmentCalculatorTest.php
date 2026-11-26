<?php

namespace Tests\Unit;

use App\Models\InvestmentProduct;
use App\Services\Investments\InvestmentCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class InvestmentCalculatorTest extends TestCase
{
    private InvestmentCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new InvestmentCalculator;
    }

    public function test_simple_monthly_schedule_pays_interest_on_the_principal_every_period(): void
    {
        $schedule = $this->calculator->schedule(
            principal: 1_000_000,
            interestRateBps: 1200,
            interestMethod: InvestmentProduct::METHOD_SIMPLE,
            interestPeriod: InvestmentProduct::PERIOD_MONTHLY,
            durationMonths: 12,
            startDate: '2026-01-01',
        );

        $this->assertCount(12, $schedule);
        $this->assertSame(120_000, $this->calculator->totalInterest($schedule));

        foreach ($schedule as $period) {
            $this->assertSame(1, $period['months']);
            $this->assertSame(1_000_000, $period['opening_balance']);
            $this->assertSame(10_000, $period['interest_amount']);
            $this->assertSame(1_010_000, $period['closing_balance']);
        }

        $this->assertSame('2026-02-01', $schedule[0]['due_date']);
        $this->assertSame('2027-01-01', $schedule[11]['due_date']);
    }

    public function test_compound_schedule_adds_each_period_interest_to_the_running_balance(): void
    {
        $schedule = $this->calculator->schedule(
            principal: 1_000_000,
            interestRateBps: 1200,
            interestMethod: InvestmentProduct::METHOD_COMPOUND,
            interestPeriod: InvestmentProduct::PERIOD_MONTHLY,
            durationMonths: 12,
            startDate: '2026-01-01',
        );

        $this->assertCount(12, $schedule);
        $this->assertGreaterThan(120_000, $this->calculator->totalInterest($schedule));
        $this->assertSame(126_825, $this->calculator->totalInterest($schedule));

        $this->assertSame(1_010_000, $schedule[0]['closing_balance']);
        $this->assertSame($schedule[0]['closing_balance'], $schedule[1]['opening_balance']);
        $this->assertGreaterThan($schedule[0]['interest_amount'], $schedule[1]['interest_amount']);
    }

    public function test_quarterly_periods_charge_interest_pro_rata_for_three_months(): void
    {
        $schedule = $this->calculator->schedule(
            principal: 1_000_000,
            interestRateBps: 1200,
            interestMethod: InvestmentProduct::METHOD_SIMPLE,
            interestPeriod: InvestmentProduct::PERIOD_QUARTERLY,
            durationMonths: 12,
            startDate: '2026-01-01',
        );

        $this->assertCount(4, $schedule);
        $this->assertSame(120_000, $this->calculator->totalInterest($schedule));

        foreach ($schedule as $period) {
            $this->assertSame(3, $period['months']);
            $this->assertSame(30_000, $period['interest_amount']);
        }
    }

    public function test_at_maturity_products_create_a_single_period_covering_the_whole_term(): void
    {
        $schedule = $this->calculator->schedule(
            principal: 1_000_000,
            interestRateBps: 1200,
            interestMethod: InvestmentProduct::METHOD_SIMPLE,
            interestPeriod: InvestmentProduct::PERIOD_AT_MATURITY,
            durationMonths: 12,
            startDate: '2026-01-01',
        );

        $this->assertCount(1, $schedule);
        $this->assertSame(12, $schedule[0]['months']);
        $this->assertSame(120_000, $schedule[0]['interest_amount']);
        $this->assertSame('2027-01-01', $schedule[0]['due_date']);
    }

    public function test_the_final_period_is_shortened_when_the_term_is_not_a_multiple_of_the_period(): void
    {
        $schedule = $this->calculator->schedule(
            principal: 1_000_000,
            interestRateBps: 1200,
            interestMethod: InvestmentProduct::METHOD_SIMPLE,
            interestPeriod: InvestmentProduct::PERIOD_QUARTERLY,
            durationMonths: 7,
            startDate: '2026-01-01',
        );

        $this->assertCount(3, $schedule);
        $this->assertSame([3, 3, 1], array_column($schedule, 'months'));
        $this->assertSame([30_000, 30_000, 10_000], array_column($schedule, 'interest_amount'));
        $this->assertSame(70_000, $this->calculator->totalInterest($schedule));
    }

    public function test_quote_returns_the_totals_and_maturity_date_for_a_product(): void
    {
        $product = new InvestmentProduct([
            'interest_rate_bps' => 1200,
            'interest_period' => InvestmentProduct::PERIOD_MONTHLY,
            'interest_method' => InvestmentProduct::METHOD_SIMPLE,
            'duration_months' => 12,
        ]);

        $quote = $this->calculator->quote($product, 1_000_000, '2026-01-01');

        $this->assertSame(120_000, $quote['expected_interest']);
        $this->assertSame(1_120_000, $quote['expected_total']);
        $this->assertSame('2027-01-01', $quote['maturity_date']);
        $this->assertSame(12, $quote['period_count']);
    }

    public function test_a_zero_principal_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule(
            principal: 0,
            interestRateBps: 1200,
            interestMethod: InvestmentProduct::METHOD_SIMPLE,
            interestPeriod: InvestmentProduct::PERIOD_MONTHLY,
            durationMonths: 12,
            startDate: '2026-01-01',
        );
    }

    public function test_a_zero_term_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->schedule(
            principal: 1_000_000,
            interestRateBps: 1200,
            interestMethod: InvestmentProduct::METHOD_SIMPLE,
            interestPeriod: InvestmentProduct::PERIOD_MONTHLY,
            durationMonths: 0,
            startDate: '2026-01-01',
        );
    }
}
