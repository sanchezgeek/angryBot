<?php

namespace App\Trading\SDK\Check\Result;

use App\Trading\SDK\Check\Contract\Dto\Out\TradingCheckFailedReason;

enum CommonOrderCheckFailureEnum implements TradingCheckFailedReason
{
    case TooManyTries;
    case UnexpectedSandboxExecutionExceptionThrown;
}
