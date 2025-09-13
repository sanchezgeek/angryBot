<?php

namespace App\Alarm\Application\Messenger\Job;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Notification\Application\Contract\Enum\SoundLength;
use App\Settings\Application\Helper\SettingsHelper;
use App\Trading\Application\Symbol\SymbolProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

#[AsMessageHandler]
final class CheckAlarmHandler
{
    private const array ALARMS = [
//        Symbol::BTCUSDT->value => [98200, 100200],
//        'SOMEUSDT' => [null, 0.102],
    ];

    private int $soundsCount;

    public function __invoke(CheckAlarm $dto): void
    {
        if ($this->appNotificationsService->isNowTimeToSleep()) {
            return;
        }

        $this->soundsCount = SettingsHelper::exact(AlarmSettings::PriceAlarm_SoundsCount);

        foreach (self::ALARMS as $symbol => [$down, $up]) {
            $ticker = $this->exchangeService->ticker(
                $this->symbolProvider->getOrInitialize($symbol)
            );
            $markPrice = $ticker->markPrice;

            if ($up !== null && $markPrice->greaterThan($up)) {
                $this->notify(sprintf('buy %s: %s > %s', $symbol, $markPrice, $up));
            }

            if ($down !== null && $markPrice->lessThan($down)) {
                $this->notify(sprintf('sell %s: %s < %s', $symbol, $markPrice, $down));
            }
        }
    }

    private function notify(string $message): void
    {
        for ($i = 0; $i <= $this->soundsCount; $i++) {
            $this->appNotificationsService->warning($message, length: SoundLength::Short);
        }
    }

    public function __construct(
        private readonly AppNotificationsServiceInterface $appNotificationsService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly SymbolProvider $symbolProvider,
    ) {
    }
}
