<?php

namespace App\Services\Investments;

use App\Models\InvestmentProduct;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Builds the interest-period schedule for a member's investment.
 *
 * All money stays in integer minor units. Rates are annual basis points, and a
 * period is charged pro-rata for the months it actually covers.
 */
class InvestmentCalculator
{
    public const PERIOD_MONTHS = [
        InvestmentProduct::PERIOD_MONTHLY => 1,
        InvestmentProduct::PERIOD_QUARTERLY => 3,
        InvestmentProduct::PERIOD_SEMI_ANNUAL => 6,
        InvestmentProduct::PERIOD_ANNUAL => 12,
    ];

    /**
     * @return array<int, array{period_no:int, period_start:string, period_end:string, due_date:string, months:int, opening_balance:int, interest_amount:int, closing_balance:int}>
     */
    public function schedule(
        int $principal,
        int $interestRateBps,
        string $interestMethod,
        string $interestPeriod,
        int $durationMonths,
        CarbonInterface|string $startDate
    ): array {
        if ($principal <= 0) {
            throw new InvalidArgumentException('Investment principal must be greater than zero.');
        }

        if ($durationMonths <= 0) {
            throw new InvalidArgumentException('Investment duration must be at least one month.');
        }

        $start = $startDate instanceof CarbonInterface
            ? CarbonImmutable::instance($startDate)
            : CarbonImmutable::parse($startDate);

        $periodMonths = $interestPeriod === InvestmentProduct::PERIOD_AT_MATURITY
            ? $durationMonths
            : (self::PERIOD_MONTHS[$interestPeriod] ?? 1);

        $compound = $interestMethod === InvestmentProduct::METHOD_COMPOUND;
        $balance = $principal;
        $elapsed = 0;
        $period = 1;
        $schedule = [];

        while ($elapsed < $durationMonths) {
            $months = min($periodMonths, $durationMonths - $elapsed);
            $periodStart = $start->addMonths($elapsed);
            $periodEnd = $start->addMonths($elapsed + $months);
            $opening = $compound ? $balance : $principal;
            $interest = $this->periodInterest($opening, $interestRateBps, $months);
            $closing = $opening + $interest;

            $schedule[] = [
                'period_no' => $period,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'due_date' => $periodEnd->toDateString(),
                'months' => $months,
                'opening_balance' => $opening,
                'interest_amount' => $interest,
                'closing_balance' => $closing,
            ];

            if ($compound) {
                $balance = $closing;
            } else {
                $balance = $principal;
            }

            $elapsed += $months;
            $period++;
        }

        return $schedule;
    }

    /**
     * @param  array<int, array{interest_amount:int}>  $schedule
     */
    public function totalInterest(array $schedule): int
    {
        return (int) array_sum(array_column($schedule, 'interest_amount'));
    }

    /**
     * @return array{schedule: array<int, array<string, mixed>>, expected_interest: int, expected_total: int, maturity_date: string, period_count: int}
     */
    public function quote(
        InvestmentProduct $product,
        int $principal,
        CarbonInterface|string|null $startDate = null
    ): array {
        $start = $startDate ?? now();
        $schedule = $this->schedule(
            $principal,
            (int) $product->interest_rate_bps,
            (string) $product->interest_method,
            (string) $product->interest_period,
            (int) $product->duration_months,
            $start
        );

        $expected = $this->totalInterest($schedule);
        $startDate = $start instanceof CarbonInterface
            ? CarbonImmutable::instance($start)
            : CarbonImmutable::parse($start);

        return [
            'schedule' => $schedule,
            'expected_interest' => $expected,
            'expected_total' => $principal + $expected,
            'maturity_date' => $startDate->addMonths((int) $product->duration_months)->toDateString(),
            'period_count' => count($schedule),
        ];
    }

    private function periodInterest(int $opening, int $annualRateBps, int $months): int
    {
        $numerator = $opening * $annualRateBps * $months;
        $denominator = 120000; // 12 months × 10,000 basis points

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
