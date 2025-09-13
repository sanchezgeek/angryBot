<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\StopPlacement\Strategies;

use App\Bot\Domain\Repository\StopRepository;
use App\Buy\Application\Service\StopPlacement\AbstractStopPlacementStrategyProcessor;
use App\Buy\Application\Service\StopPlacement\Exception\OtherStrategySuggestionException;
use App\Buy\Application\Service\StopPlacement\StopPlacementStrategyContext;
use App\Buy\Application\StopPlacementStrategy;
use App\Stop\Application\Contract\Command\CreateStop;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('buy.createStopsAfterBuy.stopPlacementStrategy.processor')]
final class AfterFirstStopUnderPositionStrategyProcessor extends AbstractStopPlacementStrategyProcessor
{
    public function __construct(
        private readonly StopRepository $stopRepository
    ) {
    }

    public function supports(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): bool
    {
        $position = $context->position;

        if ($strategy !== StopPlacementStrategy::AFTER_FIRST_STOP_UNDER_POSITION) {
            return false;
        }

        if (!$firstStopUnderPosition = $this->stopRepository->findFirstStopUnderPosition($position)) {
            throw new OtherStrategySuggestionException($strategy, StopPlacementStrategy::UNDER_POSITION);
        }
        $firstStopPrice = $firstStopUnderPosition->getPrice();

        return
            !$context->ticker->isIndexAlreadyOverStop($position->side, $firstStopPrice)
            // @todo | review logic: maybe it must be something like "if (!$ticker->isIndexAlreadyOverStop($side, $basePrice)) $triggerPrice = $side === Side::Sell ? $basePrice + 1 : $basePrice - 1; else $description = 'because index price over stop)';"
            && $context->ticker->indexPrice->deltaWith($firstStopPrice) > $context->selectedStopPriceLength
        ;
    }

    public function doProcess(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): array
    {
        $position = $context->position;

        $firstStopUnderPositionPrice = $this->stopRepository->findFirstStopUnderPosition($position)->getPrice();
        $somePriceMove = $this->someSmallPriceMove($context);

        $stopPrice = $position->isLong() ? $firstStopUnderPositionPrice - $somePriceMove : $firstStopUnderPositionPrice + $somePriceMove;

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
