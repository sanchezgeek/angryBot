<?php

declare(strict_types=1);

namespace App\Domain\Position\Helper;

use App\Bot\Domain\Position;
use App\Domain\Coin\CoinAmount;
use LogicException;

use function sprintf;

class PositionClone
{
    private ?float $liquidation = null;
    private ?float $entry = null;
    private ?float $size = null;

    private function __construct(private readonly Position $initialPosition)
    {
    }

    public static function of(Position $position): self
    {
        return new self($position);
    }

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

        if ($initial->oppositePosition) {
            $clone->setOppositePosition($oppositeClone = clone $initial->oppositePosition);
            $oppositeClone->setOppositePosition($clone);
        }

        return $clone;
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