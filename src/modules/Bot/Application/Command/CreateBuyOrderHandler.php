<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CreateBuyOrderHandler
{
    public function __construct(
        private readonly BuyOrderRepository $repository,
    ) {
    }

    public function __invoke(CreateBuyOrder $command): void
    {
        $buyOrder = new BuyOrder($command->id, $command->price, $command->volume, $command->triggerDelta, $command->positionSide);

        $this->repository->save($buyOrder);
    }
}
