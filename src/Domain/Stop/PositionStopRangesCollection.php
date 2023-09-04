<?php

declare(strict_types=1);

namespace App\Domain\Stop;

use App\Bot\Domain\Position;
use App\Domain\Price\PriceRange;
use App\Tests\Unit\Domain\Stop\StopsCollectionTest;
use Generator;

use function array_reverse;
use function ceil;

/**
 * @see \App\Tests\Unit\Domain\Stop\PositionStopRangesCollectionTest
 *
 * @template-implements Generator<StopsCollection>
 */
final class PositionStopRangesCollection implements \IteratorAggregate
{
    private Position $position;

    /**
     * @var \SplObjectStorage<PriceRange, StopsCollectionTest>
     */
    private \SplObjectStorage $stopsOnRanges;

    public function __construct(Position $position, StopsCollection $stops, float $percentStep = 10)
    {
        $min = $stops->getMinPrice();
        $max = $stops->getMaxPrice();

        $priceStep = ($position->entryPrice / 100) * ($percentStep / 100);

        $entryPrice = $position->entryPrice;

        $minBound = ceil(($entryPrice - $min) / $priceStep) * $priceStep;
        $maxBound = ceil(($max - $entryPrice) / $priceStep) * $priceStep;

        $this->stopsOnRanges = new \SplObjectStorage();

        // @todo | Only for SHORT ? | Review me

        $ranges = [];
        $rangesStops = [];
        for ($price = $entryPrice - $minBound; $price < $entryPrice + $maxBound; $price += $priceStep) {
            $rangesStops[] = $stops->grabFromRange(
                $ranges[] = PriceRange::create($price, $price + $priceStep)
            );
        }

        if ($position->isShort()) {
            $ranges = array_reverse($ranges);
            $rangesStops = array_reverse($rangesStops);
        }

        foreach ($ranges as $key => $range) {
            $this->stopsOnRanges->offsetSet($range, $rangesStops[$key]);
        }

        $this->position = $position;
    }

    /**
     * @return StopsCollection[]
     */
    public function getIterator(): Generator
    {
        foreach ($this->stopsOnRanges as $range) {
            $stops = $this->stopsOnRanges->offsetGet($range);
            yield $range->getPnlRangeForPosition($this->position) => $stops;
        }
    }
}
