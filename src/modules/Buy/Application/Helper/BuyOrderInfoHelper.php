<?php

declare(strict_types=1);

namespace App\Buy\Application\Helper;

use App\Bot\Domain\Entity\BuyOrder;

final class BuyOrderInfoHelper
{
    public static function identifier(BuyOrder $buyOrder, string $addAfter = ''): string
    {
        return sprintf('b.id=%d%s', $buyOrder->getId(), $addAfter);
    }
}
