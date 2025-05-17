<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Dto;

use App\Helper\OutputHelper;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\Dto\Out\TradingCheckFailedReason;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;

final readonly class TradingCheckResult extends AbstractTradingCheckResult
{
    public static function succeed(string|TradingCheckInterface $source, string $info, bool $quiet = false): self
    {
        $source = is_string($source) ? $source : $source->alias();

        return new self(true, $source, $info, null, $quiet);
    }

    public static function failed(string|TradingCheckInterface $source, TradingCheckFailedReason $reason, string $info, bool $quiet = false): self
    {
        $source = is_string($source) ? $source : $source->alias();

        return new self(false, $source, $info, $reason, $quiet);
    }

    public function quietClone(): self
    {
        return new self($this->success, $this->source, $this->info, $this->failedReason, true);
    }
}
