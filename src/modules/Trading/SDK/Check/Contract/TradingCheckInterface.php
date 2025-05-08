<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Contract;

use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Exception\ReferencedPositionNotFound;
use App\Trading\SDK\Check\Exception\TooManyTriesForCheck;

interface TradingCheckInterface
{
    /**
     * @throws ReferencedPositionNotFound
     */
    public function supports(CheckOrderDto $orderDto, TradingCheckContext $context): bool;

    /**
     * @throws TooManyTriesForCheck
     * @throws UnexpectedSandboxExecutionException
     */
    public function check(CheckOrderDto $orderDto, TradingCheckContext $context): AbstractTradingCheckResult;
}
