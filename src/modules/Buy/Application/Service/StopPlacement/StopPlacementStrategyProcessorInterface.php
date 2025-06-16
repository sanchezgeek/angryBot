<?php

declare(strict_types=1);

namespace App\Buy\Application\Service\StopPlacement;

use App\Buy\Application\Service\StopPlacement\Exception\OtherStrategySuggestionException;
use App\Buy\Application\StopPlacementStrategy;
use App\Stop\Application\Contract\Command\CreateStop;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

interface StopPlacementStrategyProcessorInterface
{
    /**
     * @throws OtherStrategySuggestionException
     */
    public function supports(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): bool;

    /**
     * @return CreateStop[]
     */
    public function process(StopPlacementStrategy $strategy, StopPlacementStrategyContext $context): array;
}
