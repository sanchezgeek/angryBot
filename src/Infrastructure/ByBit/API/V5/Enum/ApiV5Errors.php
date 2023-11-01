<?php

namespace App\Infrastructure\ByBit\API\V5\Enum;

enum ApiV5Errors: int
{
    case ApiRateLimitReached = 10006;
    case CannotAffordOrderCost = 110007;
    case MaxActiveCondOrdersQntReached = 110009;
    case BadRequestParams = 10001;

    public function code(): int
    {
        return $this->value;
    }

    public function desc(): string
    {
        return $this->name;
    }
}
