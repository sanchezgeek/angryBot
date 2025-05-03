<?php

declare(strict_types=1);

namespace App\Tests\Helper\Trading;

use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\Position\PositionBuilder;

final class PositionPreset
{
    public static function safeForMakeBuy(Ticker $ticker, Side $side, float $safePriceDistance): Position
    {
        $liquidation = $side->isShort() ? $ticker->lastPrice->add($safePriceDistance) : $ticker->lastPrice->sub($safePriceDistance);

        return PositionBuilder::bySide($side)->entry($ticker->lastPrice)->liq($liquidation->value())->build();
    }

    public static function NOTSafeForMakeBuy(Ticker $ticker, Side $side, float $safePriceDistance): Position
    {
        $notSafeDistance = $safePriceDistance - $ticker->symbol->minimalPriceMove();
        $liquidation = $side->isShort() ? $ticker->lastPrice->add($notSafeDistance) : $ticker->lastPrice->sub($notSafeDistance);

        return PositionBuilder::bySide($side)->entry($ticker->lastPrice)->liq($liquidation->value())->build();
    }

    public static function withoutLiquidation(Side $side, float $entryPrice = 100500): Position
    {
        return PositionBuilder::bySide($side)->entry($entryPrice)->withoutLiquidation()->build();
    }
}
