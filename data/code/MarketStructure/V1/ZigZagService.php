<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure\V1;

final class ZigZagService
{
    public function __construct(private ZigZagFinder $finder)
    {
    }

    /**
     * @return ZigZagPoint[]
     */
    public function findZigZagPoints(array $candles): array
    {
        return $this->finder->find($candles);
    }
}
