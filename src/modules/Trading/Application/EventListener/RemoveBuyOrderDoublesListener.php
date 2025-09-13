<?php

declare(strict_types=1);

namespace App\Trading\Application\EventListener;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Domain\BuyOrder\Event\BuyOrderPushedToExchange;
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

        foreach ($this->repository->getByDoublesHash($buyOrder->getDoubleHash()) as $order) {
            if ($order->getId() === $buyOrder->getId()) {
                continue;
            }

            $this->repository->remove($order);
        }
    }

    public function __construct(private BuyOrderRepository $repository)
    {
    }
}
