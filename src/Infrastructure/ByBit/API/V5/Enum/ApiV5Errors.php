<?php

namespace App\Infrastructure\ByBit\API\V5\Enum;

enum ApiV5Errors: int
{
    case ApiRateLimitReached = 10006;
    case CannotAffordOrderCost = 110007;
    case MaxActiveCondOrdersQntReached = 110009;
    case BadRequestParams = 10001;
    case BadRequestParams2 = 110092;
    case BadRequestParams3 = 110093;
    case PermissionDenied = 10005;

    public function code(): int
    {
        return $this->value;
    }

    public function desc(): string
    {
        return $this->name;
    }
}
