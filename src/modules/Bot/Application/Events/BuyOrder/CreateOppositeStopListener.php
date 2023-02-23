<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\BuyOrder;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class CreateOppositeStopListener
{
    public function __invoke(BuyOrderPushedToExchange $event): void
    {
        var_dump($event);
    }
}
