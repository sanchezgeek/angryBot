<?php

declare(strict_types=1);

namespace App\Buy\Domain\ValueObject\StopStrategy\Strategy;

use App\Buy\Domain\Enum\StopPriceDefinitionType;
use App\Buy\Domain\ValueObject\StopStrategy\AbstractStopStrategyDefinition;

final class RiskToRewardStopLength extends AbstractStopStrategyDefinition
{
    public function __construct(
        public float $rrRatio
    ) {
    }

    public static function getType(): StopPriceDefinitionType
    {
        return StopPriceDefinitionType::BasedOn_RiskToRewardRatio;
    }

    /**
     * @todo move to BuyOrder context (type without params => empty array must be returned)
     */
    public function toArray(): array
    {
        return [
            'ratio' => $this->rrRatio
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['ratio']);
    }
}
