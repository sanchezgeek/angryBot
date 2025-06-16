<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\StopPlacement;

use App\Buy\Application\StopPlacementStrategy;
use App\Helper\OutputHelper;
use RuntimeException;

abstract class AbstractStopPlacementStrategyProcessor implements StopPlacementStrategyProcessorInterface
{
    public function process(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): array
    {
        if (!$this->supports($strategy, $context)) {
            throw new RuntimeException(
                sprintf(
                    '"%s" cannot process strategy %s (BuyOrder.id = %d)',
                    OutputHelper::shortClassName($this),
                    $strategy->name,
                    $context->buyOrder->getId(),
                )
            );
        }

        return $this->doProcess($strategy, $context);
    }

    protected function someSmallPriceMove(StopPlacementStrategyContext $context): float
    {
        return $context->ticker->symbol->minimalPriceMove() * 100;
    }

    abstract protected function doProcess(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): array;
}
