<?php

declare(strict_types=1);

namespace App\Buy\Domain\ValueObject\StopStrategy\Factory;

use App\Buy\Domain\ValueObject\StopStrategy\StopCreationStrategyDefinition;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\RiskToRewardStopLength;
use RuntimeException;

final class StopCreationStrategyDefinitionStaticFactory
{
    /**
     * @var StopCreationStrategyDefinition[]
     */
    private const array AVAILABLE_STRATEGIES = [
        PredefinedStopLength::class,
        RiskToRewardStopLength::class,
    ];

    public static function fromData(array $data): StopCreationStrategyDefinition
    {
        foreach (self::AVAILABLE_STRATEGIES as $class) {
            if ($class::supports($data[StopCreationStrategyDefinition::TYPE_STORED_KEY])) {
                return $class::fromArray($data[StopCreationStrategyDefinition::PARAMS_STORED_KEY]);
            }
        }

        throw new RuntimeException(sprintf('Cannot find appropriate strategy by provided data (%s)', json_encode($data)));
    }
}
