<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;

use function implode;
use function sprintf;

trait OrderCasesTester
{
    /**
     * @param array<Stop|BuyOrder> $orders
     */
    protected static function ordersDesc(...$orders): string
    {
        $descriptions = [];
        foreach ($orders as $order) {
            $descriptions[] = sprintf('%.1f (%.3f)', $order->getPrice(), $order->getVolume());
        }
        return implode(', ', $descriptions);
    }
}
