<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\StopPlacement;

use App\Buy\Application\StopPlacementStrategy;
use App\Stop\Application\Contract\Command\CreateStop;

final class DefaultStopPlacementStrategyProcessor extends AbstractStopPlacementStrategyProcessor
{
    public function supports(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): bool
    {
        return true;
    }

    public function doProcess(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): array
    {
        $buyOrder = $context->buyOrder;
        $side = $buyOrder->getPositionSide();
        $selectedStopPriceLength = $context->selectedStopPriceLength;

        return [
            new CreateStop(
                symbol: $buyOrder->getSymbol(),
                positionSide: $side,
                volume: $buyOrder->getVolume(),
                price: $side->isLong() ? $buyOrder->getPrice() - $selectedStopPriceLength : $buyOrder->getPrice() + $selectedStopPriceLength,
                triggerDelta: null,
                context: []
            )
        ];
    }
}
