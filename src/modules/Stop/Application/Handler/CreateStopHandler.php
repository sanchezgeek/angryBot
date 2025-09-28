<?php

declare(strict_types=1);

namespace App\Stop\Application\Handler;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Stop\Application\Contract\Command\CreateStop;
use App\Stop\Application\Contract\CreateStopHandlerInterface;
use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateStopHandler implements CreateStopHandlerInterface
{
    public function __construct(
        private StopRepository $repository,
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function __invoke(CreateStop $command): Stop
    {
        $stop = new Stop(
            null,
            $command->price,
            $command->volume,
            $command->triggerDelta,
            $this->symbolProvider->replaceWithActualEntity($command->symbol),
            $command->positionSide,
            $command->context
        );

        if (!$command->dryRun) {
            $this->repository->save($stop);
        }

        return $stop;
    }
}
