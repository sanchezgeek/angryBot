<?php

namespace App\Alarm\Application\Messenger\Job;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Trading\Application\Symbol\SymbolProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

#[AsMessageHandler]
final readonly class CheckAlarmHandler
{
    private const array ALARMS = [
//        Symbol::BTCUSDT->value => [98200, 100200],
//        'SOMEUSDT' => [null, 0.102],
    ];

    public function __invoke(CheckAlarm $dto): void
    {
        if ($this->appNotificationsService->isNowTimeToSleep()) {
            return;
        }

        foreach (self::ALARMS as $symbol => [$down, $up]) {
            $ticker = $this->exchangeService->ticker(
                $this->symbolProvider->getOrInitialize($symbol)
            );
            $markPrice = $ticker->markPrice;

            if ($up !== null && $markPrice->greaterThan($up)) {
                $this->appNotificationsService->notify(sprintf('buy %s: %s > %s', $symbol, $markPrice, $up), type: 'warning');
            }

            if ($down !== null && $markPrice->lessThan($down)) {
                $this->appNotificationsService->notify(sprintf('sell %s: %s < %s', $symbol, $markPrice, $down), type: 'warning');
            }
        }
    }

    public function __construct(
        private AppNotificationsServiceInterface $appNotificationsService,
        private ExchangeServiceInterface $exchangeService,
        private SymbolProvider $symbolProvider,
    ) {
    }
}
