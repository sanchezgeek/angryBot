<?php

namespace App\Alarm\Application\Messenger\Job;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

#[AsMessageHandler]
final readonly class CheckAlarmHandler
{
    private const ALARMS = [
//        Symbol::BTCUSDT->value => [95000, 100000]
    ];

    public function __invoke(CheckAlarm $dto): void
    {
        foreach (self::ALARMS as $symbol => [$down, $up]) {
            $ticker = $this->exchangeService->ticker(Symbol::from($symbol));
            $markPrice = $ticker->markPrice;

            if ($up !== null && $markPrice->greaterThan($up)) {
                $this->appErrorLogger->error(sprintf('buy %s', $symbol));
            }

            if ($down !== null && $markPrice->lessThan($down)) {
                $this->appErrorLogger->error(sprintf('sell %s', $symbol));
            }
        }
    }

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private LoggerInterface $appErrorLogger
    ) {
    }
}
