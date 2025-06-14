<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract;

use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;

interface FindAveragePriceChangeHandlerInterface
{
    public function handle(FindAveragePriceChange $entry): FindAveragePriceChangeResult;
}
