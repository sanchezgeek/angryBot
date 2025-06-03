<?php

declare(strict_types=1);

namespace App\Tests\Mixin\DataProvider;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;

trait PositionSideAwareTest
{
    /** @var Side[] */
    private const POSITION_SIDES = [Side::Sell, Side::Buy];

    private function positionSides(): array
    {
        return self::POSITION_SIDES;
    }

    /**
     * @return array<array<Side>>
     */
    private function positionSideProvider(): iterable
    {
        return $this->positionSideIterator(static function (Side $side) {
            return [$side];
        });
    }

    private function positionSideIterator(callable $callback): iterable
    {
        foreach ($this->positionSides() as $side) {
            yield $side->title() => $callback($side);
        }
    }
}
