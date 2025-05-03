<?php

declare(strict_types=1);

namespace App\Trading\Application\Check\Dto;

use App\Helper\OutputHelper;
use App\Trading\Application\Check\Contract\AbstractTradingCheckResult;
use App\Trading\Application\Check\Contract\TradingCheckFailedReason;

final readonly class TradingCheckResult extends AbstractTradingCheckResult
{
    public static function succeed(string|object $source, string $info): self
    {
        $source = is_string($source) ? $source : OutputHelper::shortClassName($source);

        return new self(true, $source, $info);
    }

    public static function failed(string|object $source, TradingCheckFailedReason $reason, string $info): self
    {
        $source = is_string($source) ? $source : OutputHelper::shortClassName($source);

        return new self(false, $source, $info, $reason);
    }
}
