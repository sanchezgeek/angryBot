<?php

namespace App\Infrastructure\ByBit\API\V5\Enum;

enum ApiV5Errors: int
{
    case ApiRateLimitReached = 10006;
    case CannotAffordOrderCost = 110007;
    case MaxActiveCondOrdersQntReached = 110009;
    case MyDearError = 100500;

    public function code(): int
    {
        return $this->value;
    }

    public function name(): string
    {
        return $this->name;
    }
}
