<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStopHandler
{
    public function __construct(
        private StopRepository $repository,
        private SymbolProvider $symbolProvider,
    ) {
    }

    /**
     * @throws InitializeSymbolException
     * @throws SymbolEntityNotFoundException
     */
    public function __invoke(CreateStop $command): void
    {
        $stop = new Stop(
            $command->id,
            $command->price,
            $command->volume,
            $command->triggerDelta,
            $this->symbolProvider->replaceWithActualEntity($command->symbol),
            $command->positionSide,
            $command->context
        );

        $this->repository->save($stop);
    }
}
