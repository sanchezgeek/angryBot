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
        $greaterThan = self::parseSetting($this->settings->required(AlarmSettings::AlarmOnBalanceGreaterThan));
        $lessThan = self::parseSetting($this->settings->required(AlarmSettings::AlarmOnBalanceLessThan));
        if (!$greaterThan && !$lessThan) {
            return;
        }

        if ($this->limiter->consume()->isAccepted()) {
            $available = $this->exchangeAccountService->getContractWalletBalance(Coin::USDT)->available();

            if ($greaterThan && $available > $greaterThan) {
                $this->appErrorLogger->critical(sprintf('balance.available > %s', $greaterThan));
            }

            if ($lessThan && $available < $lessThan) {
                $this->appErrorLogger->critical(sprintf('balance.available < %s', $lessThan));
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
