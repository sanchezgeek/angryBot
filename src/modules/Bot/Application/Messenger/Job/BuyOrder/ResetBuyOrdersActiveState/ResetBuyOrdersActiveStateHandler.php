<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\BuyOrder\ResetBuyOrdersActiveState;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Clock\ClockInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResetBuyOrdersActiveStateHandler
{
    private const int ACTIVE_STATE_TTL = 20000;

    public function __construct(
        private BuyOrderRepository $buyOrderRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResetBuyOrdersActiveState $message): void
    {
        $now = $this->clock->now();

        $allActiveOrders = $this->buyOrderRepository->getAllActiveOrders();
        foreach ($allActiveOrders as $buyOrder) {
            if (!$activeStateSetAt = $buyOrder->getActiveStateChangeTimestamp()) {
                continue;
            }

            if ($now->getTimestamp() - $activeStateSetAt > self::ACTIVE_STATE_TTL) {
                $buyOrder->setIdle();
            }
        }

        $this->entityManager->flush();
    }
}
