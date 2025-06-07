<?php

declare(strict_types=1);

namespace App\Tests\Helper\Buy;

use App\Bot\Domain\Entity\BuyOrder;
use DateTimeImmutable;

final class BuyOrderTestHelper
{
    public static function setActive(BuyOrder $order): BuyOrder
    {
        $order->setActive(new DateTimeImmutable());

        return $order;
    }
}
