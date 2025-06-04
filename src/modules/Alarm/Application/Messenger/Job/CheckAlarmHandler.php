<?php

namespace App\Alarm\Application\Messenger\Job;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

#[AsMessageHandler]
final readonly class CheckAlarmHandler
{
    private const ALARMS = [
//        Symbol::BTCUSDT->value => [98200, 100200],
//        Symbol::ADAUSDT->value => [0.97, 1.2],
//        Symbol::TONUSDT->value => [5.87, 6.55],
//        Symbol::SOLUSDT->value => [210, 235],
//        Symbol::XRPUSDT->value => [2, 2.65],
//        Symbol::ETHUSDT->value => [3700, 3950],
//        Symbol::LINKUSDT->value => [21.7, 29.1],
//        Symbol::WIFUSDT->value => [2.77, 3.8],
//        Symbol::OPUSDT->value => [2.156, 2.65],
//        Symbol::DOGEUSDT->value => [0.38, 0.45],
//        Symbol::SUIUSDT->value => [4.28, 5.35],
//        Symbol::LTCUSDT->value => [null, 128],
//        Symbol::AVAXUSDT->value => [null, 51.2],
//        Symbol::AAVEUSDT->value => [null, 357],
    ];

    public function __invoke(CheckAlarm $dto): void
    {
        foreach (self::ALARMS as $symbol => [$down, $up]) {
            $ticker = $this->exchangeService->ticker(SymbolEnum::from($symbol));
            $markPrice = $ticker->markPrice;

            if ($up !== null && $markPrice->greaterThan($up)) {
                $this->appErrorLogger->error(sprintf('buy %s: %s > %s', $symbol, $markPrice, $up));
            }

            if ($down !== null && $markPrice->lessThan($down)) {
                $this->appErrorLogger->error(sprintf('sell %s: %s < %s', $symbol, $markPrice, $down));
            }
        }
    }

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private LoggerInterface $appErrorLogger
    ) {
    }
}
