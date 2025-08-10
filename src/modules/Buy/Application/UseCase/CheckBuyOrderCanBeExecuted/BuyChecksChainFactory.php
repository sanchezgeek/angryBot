<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyAndCheckFurtherPositionLiquidation;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePrice;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\DenyBuyIfFixationsExists;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Decorator\UseNegativeCachedResultWhileCheckDecorator;
use App\Trading\SDK\Check\Decorator\UseThrottlingWhileCheckDecorator;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class BuyChecksChainFactory
{
    public function __construct(
        private BuyAndCheckFurtherPositionLiquidation $furtherPositionLiquidationCheck,
        private BuyOnLongDistanceAndCheckAveragePrice $buyOnLongDistanceAveragePriceCheck,
        private DenyBuyIfFixationsExists $denyBuyIfFixationsExists,
        private RateLimiterFactory $checkFurtherPositionLiquidationAfterBuyLimiter,
        private AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
    ) {
    }

    public function full(): BuyChecksChain
    {
        $denyBuyIfFixationsExists = new UseThrottlingWhileCheckDecorator(
            new UseNegativeCachedResultWhileCheckDecorator(
                decorated: $this->denyBuyIfFixationsExists,
                ttl: 300,
                cacheKeyFactory: static fn(CheckOrderDto $orderDto)
                    => sprintf('%s_%s', $orderDto->symbol()->name(), $orderDto->positionSide()->value)
            ),
            $this->attemptLimitCheckerProvider->getLimiterFactory(300)
        );

        $checkPriceAveragingOnLongDistance = new UseThrottlingWhileCheckDecorator(
            new UseNegativeCachedResultWhileCheckDecorator($this->buyOnLongDistanceAveragePriceCheck),
            $this->checkFurtherPositionLiquidationAfterBuyLimiter
        );

        $furtherPositionLiquidationCheck = new UseThrottlingWhileCheckDecorator(
            new UseNegativeCachedResultWhileCheckDecorator($this->furtherPositionLiquidationCheck),
            $this->checkFurtherPositionLiquidationAfterBuyLimiter
        );

        return new BuyChecksChain(
            $this->attemptLimitCheckerProvider,
            $denyBuyIfFixationsExists,
            $checkPriceAveragingOnLongDistance,
            $furtherPositionLiquidationCheck
        );
    }
}
