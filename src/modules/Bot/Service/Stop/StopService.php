<?php

declare(strict_types=1);

namespace App\Bot\Service\Stop;

use App\Bot\Application\Command\CreateStop;
use App\Bot\Domain\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Trait\DispatchCommandTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class StopService
{
    use DispatchCommandTrait;

    public function __construct(
        private readonly StopRepository $repository,
        MessageBusInterface $commandBus,
    ) {
        $this->commandBus = $commandBus;
    }

    public function create(Ticker $ticker, Side $positionSide, float $price, float $volume, float $triggerDelta, array $context = []): int
    {
        $id = $this->repository->getNextId();

        $this->dispatchCommand(
            new CreateStop(
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
