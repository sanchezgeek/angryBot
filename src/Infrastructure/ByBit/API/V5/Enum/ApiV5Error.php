<?php

namespace App\Infrastructure\ByBit\API\V5\Enum;

use App\Infrastructure\ByBit\API\Result\ApiErrorInterface;

enum ApiV5Error: int implements ApiErrorInterface
{
    case ApiRateLimitReached = 10006;
    case CannotAffordOrderCost = 2;
    case MaxActiveCondOrdersQntReached = 110009;

    public function code(): int
    {
        return $this->value;
    }

    public function desc(): string
    {
        return $this->name;
    }
}
