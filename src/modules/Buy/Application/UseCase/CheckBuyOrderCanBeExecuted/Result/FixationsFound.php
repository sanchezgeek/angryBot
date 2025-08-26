<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result;

use App\Helper\OutputHelper;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Contract\TradingCheckInterface;

final readonly class FixationsFound extends AbstractTradingCheckResult
{
    private function __construct(
        public int $count,
        string $source,
        string $info,
        bool $quiet = false
    ) {
        parent::__construct(false, $source, $info, BuyCheckFailureEnum::FixationsStopsFound, $quiet);
    }

    public static function create(string|TradingCheckInterface $source, int $count, string $info): self
    {
        $source = is_string($source) ? $source : $source->alias();

        return new self($count, OutputHelper::shortClassName($source), $info);
    }

    public function quietClone(): AbstractTradingCheckResult
    {
        return new self($this->count, $this->source, $this->info, true);
    }
}
