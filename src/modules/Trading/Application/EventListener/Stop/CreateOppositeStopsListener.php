<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener\Stop;

use App\Domain\BuyOrder\Event\BuyOrderPushedToExchange;
use App\Stop\Application\Contract\Command\CreateOppositeStopsAfterBuy;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class CreateOppositeStopsListener
{
    public function __invoke(BuyOrderPushedToExchange $event): void
    {
        if ($event->buyOrder->isWithOppositeOrder()) {
            $this->messageBus->dispatch(
                new CreateOppositeStopsAfterBuy($event->buyOrder->getId())
            );
        }
    }

    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }
}
