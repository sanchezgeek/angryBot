<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStopHandler
{
    public function __construct(
        private StopRepository $repository,
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function __invoke(CreateStop $command): void
    {
        $stop = new Stop(
            $command->id,
            $command->price,
            $command->volume,
            $command->triggerDelta,
            $this->symbolProvider->replaceEnumWithEntity($command->symbol),
            $command->positionSide,
            $command->context
        );

        $this->repository->save($stop);
    }
}
