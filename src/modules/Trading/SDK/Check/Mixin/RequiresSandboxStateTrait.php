<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Mixin;

use App\Trading\SDK\Check\Dto\TradingCheckContext;
use RuntimeException;

trait RequiresSandboxStateTrait
{
    private static function checkCurrentSandboxStateIsSet(TradingCheckContext $context): void
    {
        if (!$context->currentSandboxState) {
            throw new RuntimeException(
                sprintf('[%s] %s::$currentSandboxState must be set', __CLASS__, TradingCheckContext::class)
            );
        }
    }
}
