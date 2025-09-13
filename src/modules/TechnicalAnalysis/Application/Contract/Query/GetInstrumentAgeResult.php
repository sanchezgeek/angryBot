<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract\Query;

use App\Helper\DateTimeHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateInterval;
use DateTimeImmutable;
use Stringable;

final class GetInstrumentAgeResult implements Stringable
{
    public function __construct(
        public SymbolInterface $symbol,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
    }

    public function getInterval(): DateInterval
    {
        return $this->to->diff($this->from);
    }

    public function countOfDays(): float
    {
        return DateTimeHelper::dateIntervalToDays($this->getInterval());
    }

    public function __toString(): string
    {
        return $this->getInterval()->format('%y years, %m months, %d days, %h hours, %i minutes, %s seconds');
    }
}
