<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;

final class Ticker
{
    public function __construct(
        public readonly Symbol $symbol,
        public readonly float  $markPrice,
        public readonly float  $indexPrice,
    ) {
    }

    public function isIndexAlreadyOverStop(Side $positionSide, float $price): bool
    {
        if ($positionSide === Side::Sell) {
            return $this->indexPrice >= $price;
        }

        if ($positionSide === Side::Buy) {
            return $this->indexPrice <= $price;
        }

        throw new \LogicException(\sprintf('Unexpected positionSide "%s"', $positionSide->value));
    }

    public function isIndexPriceAlreadyOverBuyOrderPrice(Side $positionSide, float $price): bool
    {
        if ($positionSide === Side::Sell) {
            return $this->indexPrice <= $price;
        }

        if ($positionSide === Side::Buy) {
            return $this->indexPrice >= $price;
        }

        throw new \LogicException(\sprintf('Unexpected positionSide "%s"', $positionSide->value));
    }
}
