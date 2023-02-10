<?php

declare(strict_types=1);

namespace App\Bot\Application\Command;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\StopRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CreateStopHandler
{
    public function __construct(
        private readonly StopRepository $repository,
    ) {
    }

    public function __invoke(CreateStop $command): void
    {
        $stop = new Stop($command->id, $command->price, $command->volume, $command->triggerDelta, $command->positionSide);

        $this->repository->save($stop);
    }
}
