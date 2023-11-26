<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

use LogicException;

use function sprintf;

final class Ticker
{
    public function __construct(
        public readonly Symbol $symbol,
        public readonly Price $markPrice,
        public readonly float $indexPrice,
        public readonly Price $lastPrice,
    ) {
    }

    public function isIndexAlreadyOverStop(Side $positionSide, float $price): bool
    {
        return $positionSide->isShort() ? $this->indexPrice >= $price : $this->indexPrice <= $price;
    }

    public function isIndexAlreadyOverBuyOrder(Side $positionSide, float $price): bool
    {
        if ($positionSide === Side::Sell) {
            return $this->indexPrice <= $price;
        }

        if ($positionSide === Side::Buy) {
            return $this->indexPrice >= $price;
        }

        throw new LogicException(sprintf('Unexpected positionSide "%s"', $positionSide->value));
    }
}
