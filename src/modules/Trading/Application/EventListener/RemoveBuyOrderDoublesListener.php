<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Domain\BuyOrder\Event\BuyOrderPushedToExchange;
use App\Helper\OutputHelper;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class RemoveBuyOrderDoublesListener
{
    public function __invoke(BuyOrderPushedToExchange $event): void
    {
        $buyOrder = $event->buyOrder;
        if (!$buyOrder->hasDoubleOrder()) {
            return;
        }

        $count = 0;
        foreach ($this->repository->getByDoublesHash($buyOrder->getDoubleHash()) as $order) {
            if ($order->getId() === $buyOrder->getId()) {
                continue;
            }

            $this->repository->remove($order);
            $count ++;
        }

        OutputHelper::print(sprintf('martingale (%s %s): removed %d doubles', $buyOrder->getSymbol()->name(), $buyOrder->getPositionSide()->value, $count));
    }

    public function __construct(private BuyOrderRepository $repository)
    {
    }
}
