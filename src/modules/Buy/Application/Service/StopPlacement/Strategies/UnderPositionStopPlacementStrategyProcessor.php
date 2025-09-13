<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\StopPlacement\Strategies;

use App\Buy\Application\Service\StopPlacement\AbstractStopPlacementStrategyProcessor;
use App\Buy\Application\Service\StopPlacement\StopPlacementStrategyContext;
use App\Buy\Application\StopPlacementStrategy;
use App\Stop\Application\Contract\Command\CreateStop;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('buy.createStopsAfterBuy.stopPlacementStrategy.processor')]
final class UnderPositionStopPlacementStrategyProcessor extends AbstractStopPlacementStrategyProcessor
{
    public function supports(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): bool
    {
        $position = $context->position;

        return
            $strategy === StopPlacementStrategy::UNDER_POSITION
            && !$context->ticker->isIndexAlreadyOverStop($position->side, $position->entryPrice)
            // @todo | review logic: maybe it must be something like "if (!$ticker->isIndexAlreadyOverStop($side, $basePrice)) $triggerPrice = $side === Side::Sell ? $basePrice + 1 : $basePrice - 1; else $description = 'because index price over stop)';"
            && $context->ticker->indexPrice->deltaWith($position->entryPrice) > $context->selectedStopPriceLength
        ;
    }

    public function doProcess(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): array
    {
        $position = $context->position;
        $positionPrice = $position->entryPrice;
        $somePriceMove = $this->someSmallPriceMove($context);

        $stopPrice = $position->isLong() ? $positionPrice - $somePriceMove : $positionPrice + $somePriceMove;

        return [
            new CreateStop(
                symbol: $position->symbol,
                positionSide: $position->side,
                volume: $context->buyOrder->getVolume(),
                price: $stopPrice,
                triggerDelta: null,
                context: []
            )
        ];
    }
}
