<?php

declare(strict_types=1);

namespace App\Buy\Domain\ValueObject\StopStrategy;

use App\Buy\Domain\Enum\StopPriceDefinitionType;

abstract class AbstractStopStrategyDefinition implements StopCreationStrategyDefinition
{
    abstract public static function getType(): StopPriceDefinitionType;

    final static function supports(string $strategyAlias): bool
    {
        return static::getType()->value === $strategyAlias;
    }

    public function jsonSerialize(): array
    {
        return [
            'alias' => static::getType()->value,
            'params' => $this->toArray(),
        ];
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize());
    }
}
