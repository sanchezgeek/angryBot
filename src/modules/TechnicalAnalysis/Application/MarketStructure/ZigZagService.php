<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure;

use App\TechnicalAnalysis\Application\MarketStructure\Dto\ZigZagPoint;

final class ZigZagService
{
    public function __construct(private ZigZagFinder $finder)
    {
    }

    /**
     * @param array $candles
     * @return ZigZagPoint[]
     */
    public function findZigZagPoints(array $candles): array
    {
        return $this->finder->find($candles);
    }
}
