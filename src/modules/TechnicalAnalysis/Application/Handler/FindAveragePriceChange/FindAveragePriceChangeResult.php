<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange;

use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use Stringable;

final readonly class FindAveragePriceChangeResult implements Stringable
{
    public function __construct(
        public AveragePriceChange $averagePriceChange
    ) {
    }

    public function __toString()
    {
        return (string)$this->averagePriceChange;
    }
}
