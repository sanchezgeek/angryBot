<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Stop\Helper\PnlHelper;
use Generator;

use function ceil;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceRangeTest
 */
final readonly class PriceRange
{
    public function __construct(private Price $from, private Price $to)
    {
        if ($from->greaterOrEquals($to)) {
            throw new \LogicException('$from must be greater than $to.');
        }
    }

    public static function create(Price|float $from, Price|float $to): self
    {
        $from = $from instanceof Price ? $from : Price::float($from);
        $to = $to instanceof Price ? $to : Price::float($to);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from, $to);
    }

    public static function byPositionPnlRange(Position $position, float $fromPnl, float $toPnl): self
    {
        $fromPrice = PnlHelper::getTargetPriceByPnlPercent($position, $fromPnl);
        $toPrice = PnlHelper::getTargetPriceByPnlPercent($position, $toPnl);

        return self::create($fromPrice, $toPrice);
    }

    public function isPriceInRange(float $price): bool
    {
        return $price >= $this->from->value() && $price < $this->to->value();
    }

    public function from(): Price
    {
        return $this->from;
    }

    public function to(): Price
    {
        return $this->to;
    }

    public function getPnlRangeForPosition(Position $position): string
    {
        return sprintf('% 4.0f%% .. % 4.0f%%', $this->to()->getPnlPercentFor($position), $this->from()->getPnlPercentFor($position));
    }

    public function getItemsQntByStep(int $priceStep): int
    {
        return (int)ceil(($this->to()->value() - $this->from()->value()) / $priceStep);
    }

    /**
     * @param int $step
     * @return Generator<Price>
     */
    public function byStepIterator(int $step): Generator
    {
        for ($price = $this->from()->value(); $price < $this->to()->value(); $price += $step) {
            yield Price::float($price);
        }
    }

    /**
     * @param int $qnt
     * @return Generator<Price>
     */
    public function byQntIterator(int $qnt): Generator
    {
        $delta = $this->to()->value() - $this->from()->value();

        $priceStep = $delta / $qnt;

        $resultQnt = 0;
        for ($price = $this->from()->value(); $price < $this->to()->value() && $resultQnt < $qnt; $price += $priceStep) {
            yield Price::float(PriceHelper::round($price));
            $resultQnt++;
        }
    }

    public function getMiddlePrice(): Price
    {
        return Price::float(
            ($this->from->value() + $this->to->value()) / 2
        );
    }
}
