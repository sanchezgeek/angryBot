<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\UseCase\FindAveragePriceChange;

use App\Chart\Application\Service\CandlesProvider;
use App\Clock\ClockInterface;
use App\Helper\DateTimeHelper;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use DatePeriod;
use DateTimeImmutable;

/**
 * Результат можно использовать для поиска интервала на полученном наборе kniles и получения процента от этого change и дальнейшего принятия решения
 * либо просто пропорционально
 * критерии пробоя / разворота: подтверждено объёмами (или  наоборот). чтобы понять в какую сторону открывать
 *
 *
 *
 * Ещё нужен какой-то определятор повышена ли в моменте (сегодня или за какой-то период) волатильность
 */
final readonly class FindAveragePriceChangeHandler implements AppDynamicParametersProviderInterface
{
    #[AppDynamicParameter(group: 'priceChange', name: 'significantPriceChangeByStatistics')]
    public function handle(
        #[AppDynamicParameterEvaluations(defaultValueProvider: FindAveragePriceChangeEntryEvaluationProvider::class, skipUserInput: true)]
        FindAveragePriceChangeEntry $entry
    ): float {
        $cacheKey = sprintf(
            'significantPriceChange_%s_onInterval_%s_count_%d',
            $entry->symbol->name(),
            $entry->averageOnInterval->value,
            $entry->intervalsCount
        );

        return $this->cache->get($cacheKey, fn () => $this->doHandle($entry));
    }

    private function doHandle(FindAveragePriceChangeEntry $entry): float
    {
        $symbol = $entry->symbol;
        $interval = $entry->averageOnInterval;
        $intervalsCount = $entry->intervalsCount;

        $dateInterval = $entry->averageOnInterval->toDateInterval();

        $now = $this->clock->now();
        $dayStart = $now->setTime(0, 0);
        $secondsPassed = DateTimeHelper::dateIntervalToSeconds($now->diff($dayStart));

        $secondsInInterval = DateTimeHelper::dateIntervalToSeconds($dateInterval);
        $intervalsPassed = floor($secondsPassed / $secondsInInterval);

        $to = new DateTimeImmutable()->setTimestamp($dayStart->getTimestamp() + (int)$intervalsPassed * $secondsInInterval);
        $start = new DateTimeImmutable()->setTimestamp($to->getTimestamp() - $intervalsCount * $secondsInInterval);

        $diffs = [];
        foreach (new DatePeriod($start, $dateInterval, $to) as $timePoint) {
            $candles = $this->candlesProvider->getCandles(symbol: $symbol, interval: $interval, from: $timePoint, limit: 1);
            $diffs[] = $candles[0]->priceDiffBetweenHighAndLow();
        }

        return array_sum($diffs) / count($diffs);
    }

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private ClockInterface $clock,

        #[AppDynamicParameterAutowiredArgument]
        private CandlesProvider $candlesProvider,

        #[AppDynamicParameterAutowiredArgument]
        private AveragePriceChangeCache $cache,
    ) {
    }
}
