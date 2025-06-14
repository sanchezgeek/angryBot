<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract;

use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;

final readonly class FindAveragePriceChangeResult
{
    public function __construct(
        public AveragePriceChange $averagePriceChange
    ) {
    }
}
