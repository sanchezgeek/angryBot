<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Stop\Helper\PnlHelper;
use Generator;
use Stringable;

use function ceil;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceRangeTest
 */
final readonly class PriceRange implements Stringable
{
    public function __construct(private Price $from, private Price $to, private Symbol $symbol = Symbol::BTCUSDT)
    {
        if ($from->greaterOrEquals($to)) {
            throw new \LogicException('$from must be greater than $to.');
        }
    }

    public static function create(Price|float $from, Price|float $to, Symbol $symbol = Symbol::BTCUSDT): self
    {
        $from = $symbol->makePrice(Price::toFloat($from));
        $to = $symbol->makePrice(Price::toFloat($to));

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($from, $to, $symbol);
    }

    public static function byPositionPnlRange(Position $position, float $fromPnl, float $toPnl): self
    {
        $fromPrice = PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, $fromPnl);
        $toPrice = PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, $toPnl);

        return self::create($fromPrice, $toPrice, $position->symbol);
    }

    public function isPriceInRange(Price|float $price): bool
    {
        $price = $price instanceof Price ? $price->value() : $price;

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
        return sprintf('% 5.0f%% .. % 5.0f%%', $this->to()->getPnlPercentFor($position), $this->from()->getPnlPercentFor($position));
    }

    public function getItemsQntByStep(int $priceStep): int
    {
        return (int)ceil(($this->to()->value() - $this->from()->value()) / $priceStep);
    }

    /**
     * @return Generator<Price>
     */
    public function byStepIterator(float $step): Generator
    {
        for ($price = $this->from()->value(); $price < $this->to()->value(); $price += $step) {
            yield $this->symbol->makePrice($price);
        }
    }

    public function resultCountByStep(float $step): int
    {
        return count(iterator_to_array($this->byStepIterator($step)));
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
            yield $this->symbol->makePrice($price);
            $resultQnt++;
        }
    }

    public function getMiddlePrice(): Price
    {
        return $this->symbol->makePrice(
            ($this->from->value() + $this->to->value()) / 2
        );
    }

    public function getSymbol(): Symbol
    {
        return $this->symbol;
    }

    public function __toString(): string
    {
        return sprintf('%s - %s', $this->from(), $this->to());
    }
}
