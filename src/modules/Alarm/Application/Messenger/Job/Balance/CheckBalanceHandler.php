<?php

namespace App\Alarm\Application\Messenger\Job\Balance;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Domain\Coin\Coin;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final readonly class CheckBalanceHandler
{
    private LimiterInterface $limiter;

    public function __invoke(CheckBalance $dto): void
    {
        $contractAvailableGreaterThan = self::parseSetting($this->settings->optional(AlarmSettings::AlarmOnContractAvailableBalanceGreaterThan));
        $contractAvailableLessThan = self::parseSetting($this->settings->optional(AlarmSettings::AlarmOnContractAvailableBalanceLessThan));

        $contractTotalGreaterThan = self::parseSetting($this->settings->optional(AlarmSettings::AlarmOnTotalBalanceGreaterThan));
        $contractTotalLessThan = self::parseSetting($this->settings->optional(AlarmSettings::AlarmOnTotalBalanceLessThan));

        if (
            !$contractAvailableGreaterThan
            && !$contractAvailableLessThan
            && !$contractTotalGreaterThan
            && !$contractTotalLessThan
        ) {
            return;
        }

        if ($this->limiter->consume()->isAccepted()) {
            $contractBalance = $this->exchangeAccountService->getContractWalletBalance(Coin::USDT);

            $available = $contractBalance->available();
            $total = $contractBalance->totalWithUnrealized();

            if ($contractAvailableGreaterThan && $available > $contractAvailableGreaterThan) {
                $this->appErrorLogger->critical(sprintf('contractBalance.available > %s', $contractAvailableGreaterThan));
            }

            if ($contractAvailableLessThan && $available < $contractAvailableLessThan) {
                $this->appErrorLogger->critical(sprintf('contractBalance.available < %s', $contractAvailableLessThan));
            }

            if ($contractTotalGreaterThan && $total > $contractTotalGreaterThan) {
                $this->appErrorLogger->critical(sprintf('contractBalance.TOTAL > %s', $contractTotalGreaterThan));
            }

            if ($contractTotalLessThan && $total < $contractTotalLessThan) {
                $this->appErrorLogger->critical(sprintf('contractBalance.TOTAL < %s', $contractTotalLessThan));
            }
        }
    }

    private static function parseSetting(mixed $value): bool|float
    {
        return match (true) {
            'true' => true,
            is_numeric($value) => (float)$value,
            default => false
        };
    }

    public function __construct(
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private LoggerInterface $appErrorLogger,
        private AppSettingsProviderInterface $settings,
        RateLimiterFactory $checkBalanceThrottlingLimiter,
    ) {
        $this->limiter = $checkBalanceThrottlingLimiter->create();
    }
}
