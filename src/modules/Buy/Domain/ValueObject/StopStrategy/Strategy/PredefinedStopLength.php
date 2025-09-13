<?php

declare(strict_types=1);

namespace App\Buy\Domain\ValueObject\StopStrategy\Strategy;

use App\Buy\Domain\Enum\StopPriceDefinitionType;
use App\Buy\Domain\ValueObject\StopStrategy\AbstractStopStrategyDefinition;
use App\Domain\Trading\Enum\PriceDistanceSelector;

final class PredefinedStopLength extends AbstractStopStrategyDefinition
{
    public function __construct(
        public PriceDistanceSelector $length
    ) {
    }

    public static function getType(): StopPriceDefinitionType
    {
        return StopPriceDefinitionType::BasedOn_PredefinedStopLength;
    }

    public function toArray(): array
    {
        return [
            'length' => $this->length->value,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            PriceDistanceSelector::from($data['length'])
        );
    }
}
