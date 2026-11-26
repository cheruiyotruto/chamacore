<?php

namespace App\Services\Loans;

use Carbon\CarbonImmutable;

final readonly class ScheduleLine
{
    public function __construct(
        public int $installmentNo,
        public CarbonImmutable $dueDate,
        public int $principalDue,
        public int $interestDue,
        public int $feeDue = 0,
        public int $penaltyDue = 0,
        public int $totalDue = 0,
        public int $outstandingPrincipal = 0,
    ) {
    }

    public function withPrincipal(int $principalDue): self
    {
        return new self(
            installmentNo: $this->installmentNo,
            dueDate: $this->dueDate,
            principalDue: $principalDue,
            interestDue: $this->interestDue,
            feeDue: $this->feeDue,
            penaltyDue: $this->penaltyDue,
            totalDue: $principalDue + $this->interestDue + $this->feeDue + $this->penaltyDue,
            outstandingPrincipal: $this->outstandingPrincipal,
        );
    }
}
