<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyAndCheckFurtherPositionLiquidation;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePrice;
use App\Trading\SDK\Check\Decorator\UseNegativeCachedResultWhileCheckDecorator;
use App\Trading\SDK\Check\Decorator\UseThrottlingWhileCheckDecorator;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class BuyChecksChainFactory
{
    public function __construct(
        private BuyAndCheckFurtherPositionLiquidation $furtherPositionLiquidationCheck,
        private BuyOnLongDistanceAndCheckAveragePrice $buyOnLongDistanceAveragePriceCheck,
        private RateLimiterFactory $checkFurtherPositionLiquidationAfterBuyLimiter,
        private AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
    ) {
    }

    public function full(): BuyChecksChain
    {
        return new BuyChecksChain(
            $this->attemptLimitCheckerProvider,
            new UseThrottlingWhileCheckDecorator(
                new UseNegativeCachedResultWhileCheckDecorator($this->buyOnLongDistanceAveragePriceCheck),
                $this->checkFurtherPositionLiquidationAfterBuyLimiter
            ),
            new UseThrottlingWhileCheckDecorator(
                new UseNegativeCachedResultWhileCheckDecorator($this->furtherPositionLiquidationCheck),
                $this->checkFurtherPositionLiquidationAfterBuyLimiter
            )
        );
    }
}
