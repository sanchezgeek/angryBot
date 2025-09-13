<?php

declare(strict_types=1);

namespace App\Buy\Application\Helper;

use App\Bot\Domain\Entity\BuyOrder;
use App\Domain\Price\SymbolPrice;

final class BuyOrderInfoHelper
{
    public static function identifier(BuyOrder $buyOrder, string $addAfter = ''): string
    {
        return sprintf('b.id=%d%s', $buyOrder->getId(), $addAfter);
    }

    public static function shortInlineInfo(float $volume, float|SymbolPrice $price): string
    {
        return sprintf('q=%s p=%s', $volume, $price);
    }
}
