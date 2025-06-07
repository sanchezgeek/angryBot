<?php

declare(strict_types=1);

namespace App\Domain\Price;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Helper\PnlHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use Generator;
use Stringable;

use function ceil;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Price\PriceRangeTest
 */
final readonly class PriceRange implements Stringable
{
    public function __construct(private SymbolPrice $from, private SymbolPrice $to, private SymbolInterface $symbol)
    {
        if ($from->greaterOrEquals($to)) {
            throw new \LogicException('$from must be greater than $to.');
        }
    }

    public static function create(SymbolPrice|float $from, SymbolPrice|float $to, SymbolInterface $symbol): self
    {
        $from = $symbol->makePrice(SymbolPrice::toFloat($from));
        $to = $symbol->makePrice(SymbolPrice::toFloat($to));

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

    public function isPriceInRange(SymbolPrice|float $price): bool
    {
        $price = $price instanceof SymbolPrice ? $price->value() : $price;

        return $price >= $this->from->value() && $price < $this->to->value();
    }

    public function from(): SymbolPrice
    {
        return $this->from;
    }

    public function to(): SymbolPrice
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
     * @return Generator<SymbolPrice>
     */
    public function byStepIterator(float $step, ?Side $positionSide = Side::Sell): Generator
    {
        if ($positionSide === Side::Sell) {
            for ($price = $this->from()->value(); $price < $this->to()->value(); $price += $step) {
                yield $this->symbol->makePrice($price);
            }
        } else {
            for ($price = $this->to()->value(); $price > $this->from()->value(); $price -= $step) {
                yield $this->symbol->makePrice($price);
            }
        }
    }

    public function resultCountByStep(float $step): int
    {
        return count(iterator_to_array($this->byStepIterator($step)));
    }

    /**
     * @param int $qnt
     * @return Generator<SymbolPrice>
     */
    public function byQntIterator(int $qnt, ?Side $positionSide = Side::Sell): Generator
    {
        $delta = $this->to()->value() - $this->from()->value();

        $priceStep = $delta / $qnt;

        $resultQnt = 0;
        if ($positionSide === Side::Sell) {
            for ($price = $this->from()->value(); $price < $this->to()->value() && $resultQnt < $qnt; $price += $priceStep) {
                yield $this->symbol->makePrice($price);
                $resultQnt++;
            }
        } else {
            for ($price = $this->to()->value(); $price > $this->from()->value() && $resultQnt < $qnt; $price -= $priceStep) {
                yield $this->symbol->makePrice($price);
                $resultQnt++;
            }
        }
    }

    public function getMiddlePrice(): SymbolPrice
    {
        return $this->symbol->makePrice(
            ($this->from->value() + $this->to->value()) / 2
        );
    }

    public function getSymbol(): SymbolInterface
    {
        return $this->symbol;
    }

    public function __toString(): string
    {
        return sprintf('%s - %s', $this->from(), $this->to());
    }
}
