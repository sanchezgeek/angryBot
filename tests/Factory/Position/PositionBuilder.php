<?php

declare(strict_types=1);

namespace App\Tests\Factory\Position;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Symbol\SymbolInterface;
use LogicException;
use RuntimeException;

use function sprintf;

class PositionBuilder
{
    private const DEFAULT_ENTRY = 29000;
    private const DEFAULT_SYMBOL = SymbolEnum::BTCUSDT;
    private const DEFAULT_SIZE = 0.5;
    private const DEFAULT_LEVERAGE = 100;

    private Side $side;
    private SymbolInterface $symbol = self::DEFAULT_SYMBOL;
    private float $entry = self::DEFAULT_ENTRY;
    private ?float $liquidation = null;
    private float $size = self::DEFAULT_SIZE;
    private int $leverage = self::DEFAULT_LEVERAGE;
    private ?float $unrealizedPnl = null;
    private ?Position $oppositePosition = null;

    private ?CalcPositionLiquidationPriceHandler $liquidationCalculator = null;
    private float $fundsForLiquidation;

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

    public function symbol(SymbolInterface $symbol): self
    {
        $builder = clone $this;
        $builder->symbol = $symbol;

        return $builder;
    }

    public function entry(float|SymbolPrice $entry): self
    {
        $builder = clone $this;
        $builder->entry = SymbolPrice::toFloat($entry);

        return $builder;
    }

    public function size(float $size): self
    {
        $builder = clone $this;
        $builder->size = $size;

        return $builder;
    }

    public function unrealizedPnl(float $unrealizedPnl): self
    {
        $builder = clone $this;
        $builder->unrealizedPnl = $unrealizedPnl;

        return $builder;
    }

    public function liq(float|SymbolPrice $liquidation): self
    {
        $builder = clone $this;
        $builder->liquidation = SymbolPrice::toFloat($liquidation);

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

    public function withoutLiquidation(): self
    {
        $builder = clone $this;
        $builder->liquidation = 0;

        return $builder;
    }

    public function withLiquidationCalculator(float $fundsForLiquidation): self
    {
        $this->liquidationCalculator = new CalcPositionLiquidationPriceHandler();
        $this->fundsForLiquidation = $fundsForLiquidation;

        return $this;
    }

    /**
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function build(?Position &$oppositePosition = null): Position
    {
        if ($oppositePosition && $oppositePosition->side === $this->side) {
            throw new LogicException(sprintf('Cannot set opposite position to %s: positions on the same side', $oppositePosition->side->name));
        }

        if ($oppositePosition && $this->oppositePosition) {
            throw new RuntimeException('Use one of options to specify oppositePosition: either PositionBuilder::opposite or PositionBuilder::build');
        }

        $oppositePosition ??= $this->oppositePosition;

        $side = $this->side;
        $entry = $this->entry;

        $size = $this->size;
        $positionValue = $entry * $size;
        $initialMargin = new CoinAmount($this->symbol->associatedCoin(), ($positionValue / $this->leverage));

        $isSupportPosition = $oppositePosition && $oppositePosition->size > $size;
        $isMainPosition = $oppositePosition && $oppositePosition->size < $size;

        $liquidation = null;
        if ($this->liquidation !== null) {
            assert(!$isSupportPosition, new LogicException('Opposite position size greater than self one. Position cannot have liquidation'));
            $liquidation = $this->liquidation;
        } elseif ($this->liquidationCalculator) {
            $fundsForLiquidation = $this->fundsForLiquidation;
            // ...
        }

        if ($oppositePosition) {
            if ($isSupportPosition) {
                $liquidation = 0; // @todo | liquidation | null
            } elseif ($isMainPosition) {
                $oppositePosition = PositionClone::clean($oppositePosition)->withoutLiquidation()->create();
            } else {
                $liquidation = 0; // @todo | liquidation | null
                $oppositePosition = PositionClone::clean($oppositePosition)->withoutLiquidation()->create();
            }
        }

        if ($liquidation === null) {
//            $liquidation = $side->isShort() ? $entry + 99999 : 0;
            $modifier = Percent::string('10%')->of($entry);
            $liquidation = $side->isShort() ? $entry + $modifier : $entry - $modifier;
        }


//        if (($side->isShort() && $liquidation < $entry) || ($side->isLong() && $liquidation > $entry)) {
//            throw new LogicException(sprintf('Invalid liquidation price "%s" provided (entry = "%s")', $liquidation, $entry));
//        }

        $position = new Position(
            $side,
            $this->symbol,
            $entry,
            $size,
            $positionValue,
            $liquidation,
            $initialMargin->value(),
            $this->leverage,
            $this->unrealizedPnl,
        );

        if ($oppositePosition) {
            $position->setOppositePosition($oppositePosition);

            # make testcases more handy
            $oppositePosition->setOppositePosition($position);
        }

        return $position;
    }
}
