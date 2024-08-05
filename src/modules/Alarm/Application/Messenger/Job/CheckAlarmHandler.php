<?php

namespace App\Alarm\Application\Messenger\Job;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckAlarmHandler
{
    private const UP = null;
    private const DOWN = null;

    public function __invoke(CheckAlarm $dto): void
    {
        $ticker = $this->exchangeService->ticker($dto->symbol);
        $markPrice = $ticker->markPrice;

        if (self::UP !== null && $markPrice->greaterThan(self::UP)) {
            $this->appErrorLogger->error('buy');
        }

        if (self::DOWN !== null && $markPrice->lessThan(self::DOWN)) {
            $this->appErrorLogger->error('sell');
        }
    }

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private LoggerInterface $appErrorLogger
    ) {
    }
}
