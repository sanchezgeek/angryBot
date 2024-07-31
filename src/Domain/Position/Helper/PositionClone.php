<?php

declare(strict_types=1);

namespace App\Domain\Position\Helper;

use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use LogicException;

use function sprintf;

class PositionClone
{
    private Position $initialPosition;

    private ?float $liquidation = null;
    private ?float $entry = null;
    private ?float $size = null;

    private ?Position $oppositePosition = null;

    private function __construct(Position $initialPosition)
    {
        $this->initialPosition = $initialPosition;

        if ($initialPosition->oppositePosition) {
            $this->oppositePosition = $initialPosition->oppositePosition;
        }
    }

    public static function of(Position $position): self
    {
        return new self($position);
    }

    /**
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function create(): Position
    {
        $initial = $this->initialPosition;

        $side = $initial->side;
        $symbol = $initial->symbol;

        $liquidation    = $this->liquidation ?? $initial->liquidationPrice;
        $entry          = $this->entry ?? $initial->entryPrice;
        $size           = $this->size ?? $initial->size;

        $positionValue = $entry * $size; // @todo | only linear?
        $positionBalance = $initialMargin = new CoinAmount($symbol->associatedCoin(), $positionValue / $initial->leverage->value());

        if (($side->isShort() && $liquidation < $entry) || ($side->isLong() && $liquidation > $entry)) {
            throw new LogicException(sprintf('%s: invalid liquidation price "%s" provided (entry = "%s")', __METHOD__, $liquidation, $entry));
        }

        $clone = new Position(
            $side,
            $symbol,
            $entry,
            $size,
            $positionValue,
            $liquidation,
            $initialMargin->value(),
            $positionBalance->value(),
            $initial->leverage->value(),
//            $initial->unrealizedPnl,
        );

        if ($this->oppositePosition) {
            $clone->setOppositePosition($this->oppositePosition);
        }

        return $clone;
    }

    public function clearOpposite(): self
    {
        $this->oppositePosition = null;
        return $this;
    }

    public function withOpposite(?Position $oppositePosition): self
    {
        $this->oppositePosition = $oppositePosition !== null ? $oppositePosition : null;
        return $this;
    }

    public function withLiquidation(float $liquidation): self
    {
        $this->liquidation = $liquidation;
        return $this;
    }

    public function withEntry(float $entry): self
    {
        $this->entry = $entry;
        return $this;
    }

    public function withSize(float $size): self
    {
        $this->size = $size;
        return $this;
    }
}