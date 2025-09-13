<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Dto;

use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\Dto\Out\TradingCheckFailedReason;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;

final readonly class CompositeTradingCheckResult extends AbstractTradingCheckResult
{
    /** @var AbstractTradingCheckResult[] */
    private array $results;

    public static function succeed(string|TradingCheckInterface $source, string $info, array $results, bool $quiet = false): self
    {
        $source = is_string($source) ? $source : $source->alias();

        $compositeTradingCheckResult = new self(true, $source, $info, null, $quiet);

        $compositeTradingCheckResult->results = $results;

        return $compositeTradingCheckResult;
    }

    public static function failed(string|TradingCheckInterface $source, TradingCheckFailedReason $reason, string $info, array $results, bool $quiet = false): self
    {
        $source = is_string($source) ? $source : $source->alias();

        $compositeTradingCheckResult = new self(false, $source, $info, $reason, $quiet);

        $compositeTradingCheckResult->results = $results;

        return $compositeTradingCheckResult;
    }

    public function quietClone(): self
    {
        return new self($this->success, $this->source, $this->info, $this->failedReason, true);
    }

    /**
     * @return AbstractTradingCheckResult[]
     */
    public function getResults(): array
    {
        return $this->results;
    }
}
