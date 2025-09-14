<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Service\Candles;

use App\Chart\Application\Service\CandlesProvider;
use App\Clock\ClockInterface;
use App\Domain\Trading\Enum\TimeFrame;
use App\Helper\DateTimeHelper;
use App\TechnicalAnalysis\Domain\Dto\CandleDto;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;

final readonly class PreviousCandlesProvider
{
    public function __construct(
        private CandlesProvider $candlesProvider,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return CandleDto[]
     */
    public function getPreviousCandles(SymbolInterface $symbol, TimeFrame $candleInterval, int $count, bool $includeCurrentUnfinishedInterval = false): array
    {
        $secondsInInterval = DateTimeHelper::dateIntervalToSeconds($candleInterval->toDateInterval());

        $now = $this->clock->now();
        $dayStart = $now->setTime(0, 0);
        $secondsPassed = DateTimeHelper::dateIntervalToSeconds($now->diff($dayStart));

        $intervalsPassed = floor($secondsPassed / $secondsInInterval);

        $back = $count;
        if ($includeCurrentUnfinishedInterval) {
            $back -= 1;
        }
        $to = new DateTimeImmutable()->setTimestamp($dayStart->getTimestamp() + (int)$intervalsPassed * $secondsInInterval);
        $start = new DateTimeImmutable()->setTimestamp(max($to->getTimestamp() - $back * $secondsInInterval, 0));

        $array = $this->candlesProvider->getCandles(symbol: $symbol, interval: $candleInterval, from: $start, limit: $count);
        return array_values(
            $array
        );
    }
}
