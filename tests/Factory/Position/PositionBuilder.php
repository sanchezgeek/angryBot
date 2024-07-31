<?php

declare(strict_types=1);

namespace App\Tests\Factory\Position;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use LogicException;

use function sprintf;

class PositionBuilder
{
    private const DEFAULT_ENTRY = 29000;
    private const DEFAULT_SYMBOL = Symbol::BTCUSDT;
    private const DEFAULT_SIZE = 0.5;
    private const DEFAULT_LEVERAGE = 100;

    private Side $side;
    private Symbol $symbol = self::DEFAULT_SYMBOL;
    private float $entry = self::DEFAULT_ENTRY;
    private ?float $liquidation = null;
    private float $size = self::DEFAULT_SIZE;
    private int $leverage = self::DEFAULT_LEVERAGE;
    private ?float $unrealizedPnl = null;
    private ?Position $oppositePosition = null;

    public function __construct(Side $side)
    {
        $this->side = $side;
    }

    public static function bySide(Side $side): self
    {
        return new self($side);
    }

    public static function short(): self
    {
        return new self(Side::Sell);
    }

    public static function long(): self
    {
        return new self(Side::Buy);
    }

    public static function oppositeFor(Position $position): self
    {
        return new self($position->side->getOpposite());
    }

    public function symbol(Symbol $symbol): self
    {
        $builder = clone $this;
        $builder->symbol = $symbol;

        return $builder;
    }

    public function entry(float|Price $entry): self
    {
        $builder = clone $this;
        $builder->entry = Price::toFloat($entry);

        return $builder;
    }

    public function size(float $size): self
    {
        $builder = clone $this;
        $builder->size = $size;

        return $builder;
    }

    public function liq(float|Price $liquidation): self
    {
        $builder = clone $this;
        $builder->liquidation = Price::toFloat($liquidation);

        return $builder;
    }

    public function liqDistance(float $distance = 1000): self
    {
        if ($this->liquidation !== null) {
            throw new LogicException(sprintf('Liquidation is already set to %s.', $this->liquidation));
        }

        $builder = clone $this;
        $builder->liquidation = $this->side->isShort() ? $this->entry + $distance : $this->entry - $distance;

        return $builder;
    }

    public function opposite(Position $oppositePosition): self
    {
        if ($oppositePosition->side === $this->side) {
            throw new LogicException(sprintf('Cannot set opposite position to %s: positions on the same side', $oppositePosition->side->name));
        }

        $builder = clone $this;
        $builder->oppositePosition = $oppositePosition;

        return $builder;
    }

    /**
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function build(): Position
    {
        $side = $this->side;
        $entry = $this->entry;

        $positionValue = $entry * $this->size;
        $positionBalance = $initialMargin = new CoinAmount($this->symbol->associatedCoin(), $positionValue / $this->leverage);

        $liquidation = $this->liquidation ?? ($side->isShort() ? $entry + 99999 : 0);

        if (($side->isShort() && $liquidation < $entry) || ($side->isLong() && $liquidation > $entry)) {
            throw new LogicException(sprintf('Invalid liquidation price "%s" provided (entry = "%s")', $liquidation, $entry));
        }

        $position = new Position(
            $side,
            $this->symbol,
            $entry,
            $this->size,
            $positionValue,
            $liquidation,
            $initialMargin->value(),
            $positionBalance->value(),
            $this->leverage,
            $this->unrealizedPnl,
        );

        if ($this->oppositePosition) {
            $position->setOppositePosition($this->oppositePosition);

            # make testcases more handy
            $this->oppositePosition->setOppositePosition($position);
        }

        return $position;
    }
}