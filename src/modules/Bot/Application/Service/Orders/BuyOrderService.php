<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Orders;

use App\Bot\Application\Command\CreateBuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
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

    public function create(Symbol $symbol, Side $positionSide, float $price, float $volume, float $triggerDelta, array $context = []): int
    {
        $id = $this->repository->getNextId();

        $this->dispatchCommand(
            new CreateBuyOrder(
                $id,
                $symbol,
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
