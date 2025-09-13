<?php

namespace App\Alarm\Application\Messenger\Job\Balance;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Application\AttemptsLimit\AttemptLimitCheckerProviderInterface;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Domain\Coin\Coin;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Notification\Application\Contract\Enum\SoundLength;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\LimiterInterface;

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
            $total = $contractBalance->totalWithUnrealized()->value();

            if ($contractAvailableGreaterThan && $available > $contractAvailableGreaterThan) {
                $this->notify(sprintf('contractBalance.available > %s', $contractAvailableGreaterThan), true);
            }

            if ($contractAvailableLessThan && $available < $contractAvailableLessThan) {
                $this->notify(sprintf('contractBalance.available < %s', $contractAvailableLessThan), false);
            }

            if ($contractTotalGreaterThan && $total > $contractTotalGreaterThan) {
                $this->notify(sprintf('contractBalance.TOTAL > %s', $contractTotalGreaterThan), true);
            }

            if ($contractTotalLessThan && $total < $contractTotalLessThan) {
                $this->notify(sprintf('contractBalance.TOTAL < %s', $contractTotalLessThan), false);
            }
        }
    }

    private function notify(string $message, bool $positive): void
    {
        if ($positive) {
            $this->appNotificationsService->warning($message, length: SoundLength::Short);
            $this->appNotificationsService->warning($message, length: SoundLength::Short);
        } else {
            $this->appNotificationsService->warning($message);
            $this->appNotificationsService->warning($message);
            $this->appNotificationsService->warning($message);
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
        private AppNotificationsServiceInterface $appNotificationsService,
        private ExchangeAccountServiceInterface $exchangeAccountService,
        private AppSettingsProviderInterface $settings,
        AttemptLimitCheckerProviderInterface $attemptLimitCheckerProvider,
    ) {
        $this->limiter = $attemptLimitCheckerProvider->getLimiterFactory(30)->create('balance_check');
    }
}
