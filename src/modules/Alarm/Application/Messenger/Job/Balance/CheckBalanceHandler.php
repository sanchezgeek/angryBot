<?php

namespace App\Alarm\Application\Messenger\Job\Balance;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Domain\Coin\Coin;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final readonly class CheckBalanceHandler
{
    private const ENABLED = false;
    private const AMOUNT = 20;

    private LimiterInterface $limiter;

    public function __invoke(CheckBalance $dto): void
    {
        if (!self::ENABLED) {
            return;
        }

        if ($this->limiter->consume()->isAccepted()) {
            if ($this->exchangeAccountService->getContractWalletBalance(Coin::USDT)->availableForTrade() > self::AMOUNT) {
                $this->appErrorLogger->critical('balance');
            }
        }
    }

    public function __construct(
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private LoggerInterface $appErrorLogger,
        RateLimiterFactory $checkBalanceThrottlingLimiter,
    ) {
        $this->limiter = $checkBalanceThrottlingLimiter->create();
    }
}
