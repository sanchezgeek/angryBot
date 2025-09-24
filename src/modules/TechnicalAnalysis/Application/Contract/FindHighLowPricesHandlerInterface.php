<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Contract;

use App\TechnicalAnalysis\Application\Contract\Query\FindHighLowPrices;
use App\TechnicalAnalysis\Domain\Dto\HighLow\HighLowPrices;

interface FindHighLowPricesHandlerInterface
{
    public function handle(FindHighLowPrices $entry): HighLowPrices;
}
