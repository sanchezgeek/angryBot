<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Domain\Stop\Event\StopPushedToExchange;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class CreateOppositeBuyOrdersListener
{
    public function __invoke(StopPushedToExchange $event): void
    {
        $stop = $event->stop;
        $prevPositionState = $event->prevPositionState;

        if (!(
            $stop->isWithOppositeOrder()
            || $stop->isStopAfterOtherSymbolLoss()
            || $stop->isStopAfterFixHedgeOppositePosition()
        )) {
            return;
        }

        $this->messageBus->dispatch(
            new CreateBuyOrderAfterStop($stop->getId(), $prevPositionState->size)
        );
    }

    public function __construct(private MessageBusInterface $messageBus) {}
}
