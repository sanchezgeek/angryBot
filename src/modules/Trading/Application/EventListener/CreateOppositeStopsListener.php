<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Buy\Application\Command\CreateStopsAfterBuy;
use App\Domain\BuyOrder\Event\BuyOrderPushedToExchange;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class CreateOppositeStopsListener
{
    public function __invoke(BuyOrderPushedToExchange $event): void
    {
        $buyOrder = $event->buyOrder;
        if (!$buyOrder->isWithOppositeOrder()) {
            return;
        }

        $this->messageBus->dispatch(
            new CreateStopsAfterBuy($buyOrder->getId())
        );
    }

    public function __construct(private MessageBusInterface $messageBus) {}
}
