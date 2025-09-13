<?php

declare(strict_types=1);

namespace App\Tests\Helper\Buy;

use App\Bot\Application\Messenger\Job\BuyOrder\ResetBuyOrdersActiveState\ResetBuyOrdersActiveStateHandler;
use App\Bot\Domain\Entity\BuyOrder;
use DateTimeImmutable;

final class BuyOrderTestHelper
{
    private const int ACTIVE_STATE_TTL = ResetBuyOrdersActiveStateHandler::ACTIVE_STATE_TTL;

    public static function clone(BuyOrder $order): BuyOrder
    {
        return clone $order;
    }

    public static function setActive(BuyOrder $order): BuyOrder
    {
        $order->setActive(new DateTimeImmutable());

        return $order;
    }

    public static function setActiveThatMustBeResetAtCurrentTime(BuyOrder $order): BuyOrder
    {
        $order->setActive(
            new DateTimeImmutable()->setTimestamp(
                new DateTimeImmutable()->getTimestamp() - self::ACTIVE_STATE_TTL - 1
            )
        );

        return $order;
    }
}
