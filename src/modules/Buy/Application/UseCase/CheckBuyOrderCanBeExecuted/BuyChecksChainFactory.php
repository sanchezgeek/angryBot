<?php

declare(strict_types=1);

namespace App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted;

use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyAndCheckFurtherPositionLiquidation;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePrice;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\DenyBuyIfFixationsExists;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\DenyBuyOnInsufficientContractBalance;
use App\Trading\SDK\Check\Contract\Dto\In\CheckOrderDto;
use App\Trading\SDK\Check\Decorator\UseNegativeCachedResultWhileCheckDecorator;
use App\Trading\SDK\Check\Decorator\UseThrottlingWhileCheckDecorator;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class BuyChecksChainFactory
{
    public function __construct(
        private DenyBuyOnInsufficientContractBalance $balanceCheck,
        private BuyAndCheckFurtherPositionLiquidation $furtherPositionLiquidationCheck,
        private BuyOnLongDistanceAndCheckAveragePrice $buyOnLongDistanceAveragePriceCheck,
        private DenyBuyIfFixationsExists $denyBuyIfFixationsExists,
        private RateLimiterFactory $checkFurtherPositionLiquidationAfterBuyLimiter,
        private AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
    ) {
    }

    public function full(): BuyChecksChain
    {
        $checkBalance = new UseThrottlingWhileCheckDecorator(
            new UseNegativeCachedResultWhileCheckDecorator(
                decorated: $this->balanceCheck,
                ttl: 3,
                cacheKeyFactory: static fn(CheckOrderDto $orderDto) => 'check_balance',
                quiet: false,
            ),
            $this->attemptLimitCheckerProvider->getLimiterFactory(5),
        );

        $denyBuyIfFixationsExists = new UseThrottlingWhileCheckDecorator(
            new UseNegativeCachedResultWhileCheckDecorator(
                decorated: $this->denyBuyIfFixationsExists,
                ttl: 100,
                cacheKeyFactory: static fn(CheckOrderDto $orderDto) => sprintf('%s_%s', $orderDto->symbol()->name(), $orderDto->positionSide()->value),
                quiet: false,
            ),
            $this->attemptLimitCheckerProvider->getLimiterFactory(300)
        );

        $checkPriceAveragingOnLongDistance = new UseThrottlingWhileCheckDecorator(
            new UseNegativeCachedResultWhileCheckDecorator(decorated: $this->buyOnLongDistanceAveragePriceCheck, quiet: false),
            $this->checkFurtherPositionLiquidationAfterBuyLimiter
        );

        $furtherPositionLiquidationCheck = new UseThrottlingWhileCheckDecorator(
            new UseNegativeCachedResultWhileCheckDecorator(decorated: $this->furtherPositionLiquidationCheck, quiet: false),
            $this->checkFurtherPositionLiquidationAfterBuyLimiter
        );

        return new BuyChecksChain(
            $this->attemptLimitCheckerProvider,
            $checkBalance,
            $denyBuyIfFixationsExists,
            $checkPriceAveragingOnLongDistance,
            $furtherPositionLiquidationCheck
        );
    }
}
