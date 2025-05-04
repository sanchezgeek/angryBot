<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Dto;

use App\Helper\OutputHelper;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\Dto\Out\TradingCheckFailedReason;

final readonly class TradingCheckResult extends AbstractTradingCheckResult
{
    public static function succeed(string|object $source, string $info, bool $quiet = false): self
    {
        $source = is_string($source) ? $source : OutputHelper::shortClassName($source);

        return new self(true, $source, $info, null, $quiet);
    }

    public static function failed(string|object $source, TradingCheckFailedReason $reason, string $info, bool $quiet = false): self
    {
        $source = is_string($source) ? $source : OutputHelper::shortClassName($source);

        return new self(false, $source, $info, $reason, $quiet);
    }

    public function quietClone(): self
    {
        return new self($this->success, $this->source, $this->info, $this->failedReason, true);
    }
}
