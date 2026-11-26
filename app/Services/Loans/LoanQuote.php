<?php

namespace App\Services\Loans;

use Carbon\CarbonImmutable;

final readonly class LoanQuote
{
    /**
     * @param array<int, ScheduleLine> $lines
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $principal,
        public int $totalInterest,
        public int $totalFees,
        public int $totalRepayable,
        public int $installmentCount,
        public CarbonImmutable $firstDueDate,
        public CarbonImmutable $maturityDate,
        public int $termDays,
        public string $ratePerPeriod,
        public string $effectiveAnnualRate,
        public string $method,
        public array $lines,
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'principal' => $this->principal,
            'total_interest' => $this->totalInterest,
            'total_fees' => $this->totalFees,
            'total_repayable' => $this->totalRepayable,
            'installment_count' => $this->installmentCount,
            'first_due_date' => $this->firstDueDate->toDateString(),
            'maturity_date' => $this->maturityDate->toDateString(),
            'term_days' => $this->termDays,
            'rate_per_period' => $this->ratePerPeriod,
            'effective_annual_rate' => $this->effectiveAnnualRate,
            'method' => $this->method,
            'metadata' => $this->metadata,
            'lines' => array_map(fn (ScheduleLine $line) => [
                'installment_no' => $line->installmentNo,
                'due_date' => $line->dueDate->toDateString(),
                'principal_due' => $line->principalDue,
                'interest_due' => $line->interestDue,
                'fee_due' => $line->feeDue,
                'total_due' => $line->totalDue,
                'outstanding_principal' => $line->outstandingPrincipal,
            ], $this->lines),
        ];
    }

    public function installmentAmount(): int
    {
        if ($this->installmentCount === 0) {
            return 0;
        }

        return (int) round($this->totalRepayable / $this->installmentCount);
    }

    public function interestRatePercentage(): string
    {
        return number_format(((float) $this->ratePerPeriod) * 100, 4).'%';
    }
}
