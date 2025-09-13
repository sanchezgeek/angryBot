<?php

declare(strict_types=1);

namespace App\Buy\Domain\ValueObject\StopStrategy;

use App\Buy\Domain\Enum\StopPriceDefinitionType;
use JsonSerializable;
use Stringable;

interface StopCreationStrategyDefinition extends JsonSerializable, Stringable
{
    public const string TYPE_STORED_KEY = 'type';
    public const string PARAMS_STORED_KEY = 'params';

    static function supports(string $strategyAlias): bool;
    public static function getType(): StopPriceDefinitionType;
    public function toArray(): array;
    public static function fromArray(array $data): self;
}
