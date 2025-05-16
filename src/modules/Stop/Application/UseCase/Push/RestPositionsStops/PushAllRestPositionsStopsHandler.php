<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\Push\RestPositionsStops;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStops;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushStopsHandler;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PushAllRestPositionsStopsHandler
{
    public function __invoke(PushAllRestPositionsStops $message): void
    {
        foreach ($this->positionService->getPositionsWithoutLiquidation() as $position) {
            $message = new PushStops($position->symbol, $position->side);

            $this->innerHandler->__invoke($message);
        }
    }

    public function __construct(
        private ByBitLinearPositionService $positionService,
        private PushStopsHandler $innerHandler,
    ) {
    }
}
