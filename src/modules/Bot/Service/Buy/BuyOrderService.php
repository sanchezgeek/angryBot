<?php

declare(strict_types=1);

namespace App\Bot\Service\Buy;

use App\Bot\Application\Command\CreateBuyOrder;
use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class BuyOrderService
{
    use DispatchCommandTrait;

    public function __construct(
        private readonly BuyOrderRepository $repository,
        MessageBusInterface $commandBus,
    ) {
        $this->commandBus = $commandBus;
    }

    public function create(Side $positionSide, float $price, float $volume, float $triggerDelta, array $context = []): int
    {
        $id = $this->repository->getNextId();

        $this->dispatchCommand(
            new CreateBuyOrder(
                $id,
                $positionSide,
                $volume,
                $price,
                $triggerDelta,
                $context
            ),
        );

        return $id;
    }
}
