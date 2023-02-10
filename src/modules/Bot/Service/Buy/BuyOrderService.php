<?php

declare(strict_types=1);

namespace App\Bot\Service\Buy;

use App\Bot\Application\Command\CreateBuyOrder;
use App\Bot\Application\Command\CreateStop;
use App\Bot\Domain\BuyOrderRepository;
use App\Bot\Domain\StopRepository;
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

    public function create(Ticker $ticker, Side $positionSide, float $price, float $volume, float $triggerDelta): int
    {
        $id = $this->repository->getNextId();

        $this->dispatchCommand(
            new CreateBuyOrder(
                $id,
                $positionSide,
                $ticker,
                $volume,
                $price,
                $triggerDelta
            ),
        );

        return $id;
    }
}
