<?php

namespace App\Services\Loans;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Deterministic loan quotation and repayment schedule engine.
 *
 * All monetary inputs/outputs are integer minor units (cents).
 * All intermediate arithmetic uses bcmath decimal strings.
 *
 * @see docs/CALCULATIONS.md
 */
class LoanCalculator
{
    public const METHOD_FLAT = 'flat';
    public const METHOD_REDUCING_ANNUITY = 'reducing_annuity';
    public const METHOD_REDUCING_EQUAL_PRINCIPAL = 'reducing_equal_principal';
    public const METHOD_FIXED = 'fixed';

    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_ANNUAL = 'annual';

    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';

    public const DAY_COUNT_ACTUAL_365 = 'actual_365';
    public const DAY_COUNT_ACTUAL_366 = 'actual_366';
    public const DAY_COUNT_THIRTY_360 = 'thirty_360';

    public const ACCRUAL_EFFECTIVE = 'effective';
    public const ACCRUAL_SIMPLE = 'simple';

    private const SCALE = 12;

    /**
     * @param array{
     *     principal: int,
     *     rate_bps: int,
     *     interest_period: string,
     *     method: string,
     *     repayment_frequency: string,
     *     duration: int,
     *     duration_unit: string,
     *     day_count?: string,
     *     accrual_mode?: string,
     *     start_date: string|\DateTimeInterface,
     *     fixed_interest?: int,
     *     fee_amount?: int,
     *     repayment_day?: int|null,
     * } $terms
     */
    public function quote(array $terms): LoanQuote
    {
        $this->validateTerms($terms);

        $principal = (int) $terms['principal'];
        $rateBps = (int) $terms['rate_bps'];
        $interestPeriod = $terms['interest_period'];
        $method = $terms['method'];
        $frequency = $terms['repayment_frequency'];
        $dayCount = $terms['day_count'] ?? self::DAY_COUNT_ACTUAL_365;
        $accrual = $terms['accrual_mode'] ?? self::ACCRUAL_EFFECTIVE;
        $feeAmount = (int) ($terms['fee_amount'] ?? 0);
        $start = CarbonImmutable::parse($terms['start_date'])->startOfDay();

        [$installmentCount, $dueDates, $maturity] = $this->buildTerm(
            $start,
            (int) $terms['duration'],
            $terms['duration_unit'],
            $frequency,
            $terms['repayment_day'] ?? null
        );

        if ($installmentCount < 1) {
            throw new InvalidArgumentException('A loan must have at least one installment.');
        }

        $periodDays = $this->quotedPeriodDays($interestPeriod, $dayCount);
        $termDays = $start->diffInDays($maturity);

        $lines = [];
        $totalInterest = '0';

        if ($method === self::METHOD_FLAT || $method === self::METHOD_FIXED) {
            if ($method === self::METHOD_FLAT) {
                $quotedPeriods = $this->bcdiv((string) $termDays, (string) $periodDays);
                $totalInterest = $this->bcmul(
                    $this->bcmul((string) $principal, $this->rate($rateBps)),
                    $quotedPeriods
                );
            } else {
                $totalInterest = (string) max(0, (int) ($terms['fixed_interest'] ?? 0));
            }

            // $totalInterest is already expressed in minor units because the
            // principal passed to the calculator is in minor units.
            $interestCents = (int) $this->bcround($totalInterest, 0);
            $totalRepayable = $principal + $interestCents + $feeAmount;

            $basePrincipal = intdiv($principal, $installmentCount);
            $baseInterest = intdiv($interestCents, $installmentCount);
            $baseFee = intdiv($feeAmount, $installmentCount);
            $principalLeft = $principal;
            $interestLeft = $interestCents;
            $feeLeft = $feeAmount;

            foreach ($dueDates as $index => $dueDate) {
                $isLast = $index === $installmentCount - 1;
                $principalDue = $isLast ? $principalLeft : $basePrincipal;
                $interestDue = $isLast ? $interestLeft : $baseInterest;
                $feeDue = $isLast ? $feeLeft : $baseFee;

                $principalLeft -= $principalDue;
                $interestLeft -= $interestDue;
                $feeLeft -= $feeDue;

                $lines[] = new ScheduleLine(
                    installmentNo: $index + 1,
                    dueDate: $dueDate,
                    principalDue: $principalDue,
                    interestDue: $interestDue,
                    feeDue: $feeDue,
                    penaltyDue: 0,
                    totalDue: $principalDue + $interestDue + $feeDue,
                    outstandingPrincipal: max(0, $principalLeft),
                );
            }

            return new LoanQuote(
                principal: $principal,
                totalInterest: $interestCents,
                totalFees: $feeAmount,
                totalRepayable: $totalRepayable,
                installmentCount: $installmentCount,
                firstDueDate: $dueDates[0],
                maturityDate: $maturity,
                termDays: $termDays,
                ratePerPeriod: $this->periodRate($rateBps, $interestPeriod, $start, $dueDates[0], $dayCount, $accrual),
                effectiveAnnualRate: $this->effectiveAnnualRate($rateBps, $interestPeriod, $dayCount, $accrual),
                method: $method,
                lines: $lines,
                metadata: [
                    'quoted_period_days' => $periodDays,
                    'term_days' => $termDays,
                    'accrual_mode' => $accrual,
                    'day_count' => $dayCount,
                    'freight' => 'All interest computed once from the quoted basis; no duplicate accrual.',
                ],
            );
        }

        // Reducing-balance methods accrue on the outstanding principal.
        // $principal and $outstanding are integer minor units throughout this
        // branch, so interest is already in minor units after multiplying by
        // the (unit-less) period rate.
        $outstanding = (string) $principal;
        $annuityPayment = null;
        $rateForFirstPeriod = $this->periodRate(
            $rateBps,
            $interestPeriod,
            $start,
            $dueDates[0],
            $dayCount,
            $accrual
        );

        if ($method === self::METHOD_REDUCING_ANNUITY) {
            $annuityPayment = $this->annuityPayment($principal, $rateForFirstPeriod, $installmentCount);
        }

        $totalInterestCents = 0;
        $totalFeeCents = 0;
        $previousDue = $start;

        foreach ($dueDates as $index => $dueDate) {
            $isLast = $index === $installmentCount - 1;
            $remaining = $installmentCount - $index;

            $periodRate = $this->periodRate(
                $rateBps,
                $interestPeriod,
                $previousDue,
                $dueDate,
                $dayCount,
                $accrual
            );

            $interestCents = (int) $this->bcround($this->bcmul($outstanding, $periodRate), 0);
            $outstandingCents = (int) $outstanding;

            if ($method === self::METHOD_REDUCING_ANNUITY) {
                if ($isLast) {
                    $principalDue = $outstandingCents;
                } else {
                    // Recalculate the level payment for the remaining term so
                    // that varying calendar period lengths cannot cause drift.
                    $payment = $this->annuityPayment(
                        $outstandingCents,
                        $periodRate,
                        $remaining
                    );
                    $principalDue = $payment - $interestCents;
                    if ($principalDue <= 0) {
                        $principalDue = (int) $this->bcround(
                            $this->bcdiv($outstanding, (string) $remaining),
                            0
                        );
                    }
                    if ($principalDue > $outstandingCents) {
                        $principalDue = $outstandingCents;
                    }
                }
            } else {
                $principalDue = $isLast
                    ? $outstandingCents
                    : (int) $this->bcround(
                        $this->bcdiv((string) $principal, (string) $installmentCount),
                        0
                    );
                if ($principalDue > $outstandingCents) {
                    $principalDue = $outstandingCents;
                }
            }

            $feeDue = $isLast
                ? $feeAmount - $totalFeeCents
                : (int) $this->bcround(
                    $this->bcdiv((string) $feeAmount, (string) $installmentCount),
                    0
                );
            if ($feeDue < 0) {
                $feeDue = 0;
            }

            $outstanding = $this->bcsub($outstanding, (string) $principalDue);
            if ($this->bccomp($outstanding, '0') < 0) {
                $outstanding = '0';
            }

            $totalInterestCents += $interestCents;
            $totalFeeCents += $feeDue;

            $lines[] = new ScheduleLine(
                installmentNo: $index + 1,
                dueDate: $dueDate,
                principalDue: $principalDue,
                interestDue: $interestCents,
                feeDue: $feeDue,
                penaltyDue: 0,
                totalDue: $principalDue + $interestCents + $feeDue,
                outstandingPrincipal: (int) $outstanding,
            );

            $previousDue = $dueDate;
        }

        // Absorb rounding drift into the final principal line.
        $scheduledPrincipal = array_sum(array_map(fn (ScheduleLine $l) => $l->principalDue, $lines));
        $drift = $principal - $scheduledPrincipal;
        if ($drift !== 0) {
            $last = $lines[count($lines) - 1];
            $lines[count($lines) - 1] = $last->withPrincipal($last->principalDue + $drift);
        }

        return new LoanQuote(
            principal: $principal,
            totalInterest: $totalInterestCents,
            totalFees: $totalFeeCents,
            totalRepayable: $principal + $totalInterestCents + $totalFeeCents,
            installmentCount: $installmentCount,
            firstDueDate: $dueDates[0],
            maturityDate: $maturity,
            termDays: $termDays,
            ratePerPeriod: $rateForFirstPeriod,
            effectiveAnnualRate: $this->effectiveAnnualRate($rateBps, $interestPeriod, $dayCount, $accrual),
            method: $method,
            lines: $lines,
            metadata: [
                'quoted_period_days' => $periodDays,
                'term_days' => $termDays,
                'accrual_mode' => $accrual,
                'day_count' => $dayCount,
                'annuity_first_payment' => $annuityPayment,
            ],
        );
    }

    /**
     * Convert a quoted rate (bps per interest period) into a rate for the actual
     * repayment period between two dates.
     */
    public function periodRate(
        int $rateBps,
        string $interestPeriod,
        CarbonImmutable|\DateTimeInterface $from,
        CarbonImmutable|\DateTimeInterface $to,
        string $dayCount = self::DAY_COUNT_ACTUAL_365,
        string $accrual = self::ACCRUAL_EFFECTIVE
    ): string {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        $days = max(1, $from->diffInDays($to));
        $quotedDays = $this->quotedPeriodDays($interestPeriod, $dayCount);
        $rate = $this->rate($rateBps);

        if ($accrual === self::ACCRUAL_SIMPLE) {
            return $this->bcdiv($this->bcmul($rate, (string) $days), (string) $quotedDays);
        }

        // (1 + r)^(days/quotedDays) - 1. The quoted period is kept as an exact
        // fraction because bcmath cannot raise a number to a fractional power
        // on every PHP build (bcpow() rejects exponents with a fraction).
        [$quotedDaysNumerator, $quotedDaysDenominator] = $this->quotedPeriodFraction($interestPeriod, $dayCount);

        return $this->bcsub(
            $this->bcpowFraction(
                $this->bcadd('1', $rate),
                $days * $quotedDaysDenominator,
                $quotedDaysNumerator
            ),
            '1'
        );
    }

    public function effectiveAnnualRate(
        int $rateBps,
        string $interestPeriod,
        string $dayCount = self::DAY_COUNT_ACTUAL_365,
        string $accrual = self::ACCRUAL_EFFECTIVE
    ): string {
        $quotedDays = $this->quotedPeriodDays($interestPeriod, $dayCount);
        $rate = $this->rate($rateBps);

        if ($accrual === self::ACCRUAL_SIMPLE) {
            return $this->bcdiv($this->bcmul($rate, '365'), (string) $quotedDays);
        }

        // (1 + r)^(365/quotedDays) - 1, expressed as an exact fraction so the
        // exponent never reaches bcpow() with a fractional part.
        [$quotedDaysNumerator, $quotedDaysDenominator] = $this->quotedPeriodFraction($interestPeriod, $dayCount);

        return $this->bcsub(
            $this->bcpowFraction(
                $this->bcadd('1', $rate),
                365 * $quotedDaysDenominator,
                $quotedDaysNumerator
            ),
            '1'
        );
    }

    public function annuityPayment(int $principalCents, string $periodRate, int $installments): int
    {
        if ($installments <= 0) {
            return 0;
        }

        $principal = $this->fromCents($principalCents);

        if ($this->bccomp($periodRate, '0') === 0) {
            return (int) ceil($principalCents / $installments);
        }

        $factor = $this->bcsub('1', $this->bcpow($this->bcadd('1', $periodRate), (string) (-1 * $installments)));

        if ($this->bccomp($factor, '0') === 0) {
            return (int) ceil($principalCents / $installments);
        }

        return $this->toCents($this->bcdiv($this->bcmul($principal, $periodRate), $factor));
    }

    /**
     * @return array{0:int,1:array<int,CarbonImmutable>,2:CarbonImmutable}
     */
    public function buildTerm(
        CarbonImmutable $start,
        int $duration,
        string $unit,
        string $frequency,
        ?int $repaymentDay = null
    ): array {
        if ($duration < 1) {
            throw new InvalidArgumentException('Duration must be at least one unit.');
        }

        $start = $start->startOfDay();

        $end = match ($unit) {
            'days' => $start->addDays($duration),
            'weeks' => $start->addWeeks($duration),
            'months' => $start->addMonthsNoOverflow($duration),
            default => throw new InvalidArgumentException("Unsupported duration unit [{$unit}]."),
        };

        $dates = [];
        $cursor = $start;

        if ($frequency === self::FREQUENCY_WEEKLY) {
            while (true) {
                $cursor = $cursor->addDays(7);
                if ($cursor->greaterThan($end)) {
                    break;
                }
                $dates[] = $cursor;
                if ($cursor->equalTo($end)) {
                    break;
                }
            }
            // Ensure the loan matures on the configured end date even when the
            // duration is not an exact multiple of seven days.
            if (empty($dates) || $dates[count($dates) - 1]->lessThan($end)) {
                $dates[] = $end;
            }
        } elseif ($frequency === self::FREQUENCY_MONTHLY) {
            $anchorDay = $repaymentDay ?: $start->day;
            while (true) {
                $cursor = $cursor->addMonthNoOverflow();
                if ($repaymentDay) {
                    $cursor = $cursor->setDay(min($repaymentDay, $cursor->daysInMonth));
                }
                if ($cursor->greaterThan($end)) {
                    break;
                }
                $dates[] = $cursor;
                if ($cursor->equalTo($end)) {
                    break;
                }
            }
            if (empty($dates) || $dates[count($dates) - 1]->lessThan($end)) {
                $dates[] = $end;
            }
        } else {
            throw new InvalidArgumentException("Unsupported repayment frequency [{$frequency}].");
        }

        $dates = array_values(array_unique(array_map(fn ($d) => $d->toDateString(), $dates)));
        $dates = array_map(fn ($d) => CarbonImmutable::parse($d), $dates);
        sort($dates);

        return [count($dates), $dates, $end];
    }

    public function quotedPeriodDays(string $interestPeriod, string $dayCount): float
    {
        $daysInYear = match ($dayCount) {
            self::DAY_COUNT_ACTUAL_365 => 365.0,
            self::DAY_COUNT_ACTUAL_366 => 366.0,
            self::DAY_COUNT_THIRTY_360 => 360.0,
            default => throw new InvalidArgumentException("Unsupported day-count convention [{$dayCount}]."),
        };

        return match ($interestPeriod) {
            self::PERIOD_WEEKLY => 7.0,
            self::PERIOD_MONTHLY => $dayCount === self::DAY_COUNT_THIRTY_360 ? 30.0 : $daysInYear / 12,
            self::PERIOD_ANNUAL => $daysInYear,
            default => throw new InvalidArgumentException("Unsupported interest period [{$interestPeriod}]."),
        };
    }

    /**
     * The quoted interest period as an exact fraction of days.
     *
     * @return array{0:int,1:int} Numerator and denominator.
     */
    private function quotedPeriodFraction(string $interestPeriod, string $dayCount): array
    {
        $daysInYear = match ($dayCount) {
            self::DAY_COUNT_ACTUAL_365 => 365,
            self::DAY_COUNT_ACTUAL_366 => 366,
            self::DAY_COUNT_THIRTY_360 => 360,
            default => throw new InvalidArgumentException("Unsupported day-count convention [{$dayCount}]."),
        };

        return match ($interestPeriod) {
            self::PERIOD_WEEKLY => [7, 1],
            self::PERIOD_MONTHLY => $dayCount === self::DAY_COUNT_THIRTY_360 ? [30, 1] : [$daysInYear, 12],
            self::PERIOD_ANNUAL => [$daysInYear, 1],
            default => throw new InvalidArgumentException("Unsupported interest period [{$interestPeriod}]."),
        };
    }

    private function validateTerms(array $terms): void
    {
        if (($terms['principal'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Principal must be greater than zero.');
        }
        if (($terms['rate_bps'] ?? -1) < 0) {
            throw new InvalidArgumentException('Interest rate cannot be negative.');
        }
        if (! in_array($terms['interest_period'] ?? null, [self::PERIOD_WEEKLY, self::PERIOD_MONTHLY, self::PERIOD_ANNUAL], true)) {
            throw new InvalidArgumentException('Invalid interest period.');
        }
        if (! in_array($terms['method'] ?? null, [
            self::METHOD_FLAT,
            self::METHOD_REDUCING_ANNUITY,
            self::METHOD_REDUCING_EQUAL_PRINCIPAL,
            self::METHOD_FIXED,
        ], true)) {
            throw new InvalidArgumentException('Invalid interest calculation method.');
        }
        if (! in_array($terms['repayment_frequency'] ?? null, [self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY], true)) {
            throw new InvalidArgumentException('Invalid repayment frequency.');
        }
        if (($terms['duration'] ?? 0) < 1) {
            throw new InvalidArgumentException('Invalid duration.');
        }
        if ($terms['method'] === self::METHOD_FIXED && ($terms['fixed_interest'] ?? 0) < 0) {
            throw new InvalidArgumentException('Fixed interest cannot be negative.');
        }
    }

    private function rate(int $bps): string
    {
        return $this->bcdiv((string) $bps, '10000', self::SCALE);
    }

    private function fromCents(int $cents): string
    {
        return $this->bcdiv((string) $cents, '100', self::SCALE);
    }

    private function toCents(string $amount): int
    {
        $rounded = $this->bcround($this->bcmul($amount, '100'), 0);

        return (int) $rounded;
    }

    private function bcmul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    private function bcdiv(string $a, string $b): string
    {
        if ($this->bccomp($b, '0') === 0) {
            throw new RuntimeException('Division by zero in loan calculation.');
        }

        return bcdiv($a, $b, self::SCALE);
    }

    private function bcadd(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    private function bcsub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    private function bccomp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    private function bcpow(string $base, string $exponent): string
    {
        if ($this->bccomp($base, '0') === 0 && $this->bccomp($exponent, '0') < 0) {
            throw new RuntimeException('Invalid negative exponent.');
        }

        return bcpow($base, $exponent, self::SCALE);
    }

    /**
     * Raise a number to a rational power without handing bcpow() a fractional
     * exponent, which newer PHP builds reject outright.
     */
    private function bcpowFraction(string $base, int $numerator, int $denominator): string
    {
        if ($denominator < 1) {
            throw new RuntimeException('Invalid fractional exponent.');
        }

        if ($numerator === 0) {
            return '1';
        }

        if ($numerator < 0) {
            return $this->bcdiv('1', $this->bcpowFraction($base, -$numerator, $denominator));
        }

        $divisor = $this->gcd($numerator, $denominator);
        $numerator = intdiv($numerator, $divisor);
        $denominator = intdiv($denominator, $divisor);

        $workScale = self::SCALE + 6;

        // Take the root first: it keeps intermediate values close to 1 instead
        // of allowing large integer powers to balloon before the root is taken.
        $rooted = $denominator === 1
            ? $base
            : $this->bcroot($base, $denominator, $workScale);

        $powered = bcpow($rooted, (string) $numerator, $workScale);

        return bcadd($powered, '0', self::SCALE);
    }

    /**
     * Integer nth root via Newton's method, evaluated with bcmath.
     */
    private function bcroot(string $base, int $degree, ?int $scale = null): string
    {
        $scale ??= self::SCALE;

        if ($degree < 1) {
            throw new RuntimeException('Invalid root degree.');
        }

        if ($degree === 1) {
            return bcadd($base, '0', $scale);
        }

        if ($this->bccomp($base, '1') === 0) {
            return bcadd('1', '0', $scale);
        }

        if ($this->bccomp($base, '0') === 0) {
            return '0';
        }

        if ($this->bccomp($base, '0') < 0) {
            if ($degree % 2 === 0) {
                throw new RuntimeException('Cannot take an even root of a negative number.');
            }

            return bcmul('-1', $this->bcroot(bcmul('-1', $base, $scale), $degree, $scale), $scale);
        }

        $workScale = $scale + 6;
        $guess = pow((float) $base, 1 / $degree);
        $guess = is_finite($guess) && $guess > 0.0 ? $guess : 1.0;
        $x = bcadd((string) $guess, '0', $workScale);

        for ($i = 0; $i < 100; $i++) {
            $power = bcpow($x, (string) ($degree - 1), $workScale);
            $next = bcdiv(
                bcadd(
                    bcmul((string) ($degree - 1), $x, $workScale),
                    bcdiv($base, $power, $workScale),
                    $workScale
                ),
                (string) $degree,
                $workScale
            );

            $converged = bccomp($next, $x, $workScale - 4) === 0;
            $x = $next;

            if ($converged) {
                break;
            }
        }

        return bcadd($x, '0', $scale);
    }

    private function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, $a);
    }

    /**
     * Round half-up using bcmath (bcround is not available on all builds).
     */
    private function bcround(string $value, int $precision): string
    {
        $factor = bcpow('10', (string) $precision, 0);
        $scaled = bcmul($value, $factor, self::SCALE);
        $isNegative = str_starts_with($scaled, '-');
        $absolute = ltrim($scaled, '-');
        $parts = explode('.', $absolute, 2);
        $integer = $parts[0] === '' ? '0' : $parts[0];
        $fraction = $parts[1] ?? '';

        if ($fraction !== '' && (int) $fraction[0] >= 5) {
            $integer = bcadd($integer, '1', 0);
        }

        return ($isNegative ? '-' : '') . $integer;
    }
}
