<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyAndCheckFurtherPositionLiquidation;
use App\Trading\SDK\Check\Decorator\UseNegativeCachedResultWhileCheckDecorator;
use App\Trading\SDK\Check\Decorator\UseThrottlingWhileCheckDecorator;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class BuyChecksChainFactory
{
    public function __construct(
        private BuyAndCheckFurtherPositionLiquidation $furtherPositionLiquidationCheck,
        private RateLimiterFactory $checkFurtherPositionLiquidationAfterBuyLimiter,
    ) {
    }

    public function full(): BuyChecksChain
    {
        return new BuyChecksChain(
            new UseThrottlingWhileCheckDecorator(
                new UseNegativeCachedResultWhileCheckDecorator($this->furtherPositionLiquidationCheck),
                $this->checkFurtherPositionLiquidationAfterBuyLimiter
            )
        );
    }
}
