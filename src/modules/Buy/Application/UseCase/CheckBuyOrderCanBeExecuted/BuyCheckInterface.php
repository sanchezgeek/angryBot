<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Trading\Application\Check\Contract\AbstractTradingCheckResult;
use App\Trading\Application\Check\Dto\TradingCheckContext;
use App\Trading\Application\Check\Exception\TooManyTriesForCheck;


interface BuyCheckInterface
{
    public function supports(MarketBuyEntryDto $dto, TradingCheckContext $context): bool;

    /**
     * @throws TooManyTriesForCheck
     * @throws UnexpectedSandboxExecutionException
     */
    public function check(MarketBuyEntryDto $dto, TradingCheckContext $context): AbstractTradingCheckResult;
}
