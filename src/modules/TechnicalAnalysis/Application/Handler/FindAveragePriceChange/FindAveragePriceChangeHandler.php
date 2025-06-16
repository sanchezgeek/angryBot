<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange;

use App\Chart\Application\Service\CandlesProvider;
use App\Clock\ClockInterface;
use App\Domain\Value\Percent\Percent;
use App\Helper\DateTimeHelper;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeResult;
use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
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
final readonly class FindAveragePriceChangeHandler implements FindAveragePriceChangeHandlerInterface, AppDynamicParametersProviderInterface
{
    #[AppDynamicParameter(group: 'priceChange', name: 'averagePriceChange')]
    public function handle(
        #[AppDynamicParameterEvaluations(defaultValueProvider: FindAveragePriceChangeEntryEvaluationProvider::class, skipUserInput: true)]
        FindAveragePriceChange $entry
    ): FindAveragePriceChangeResult {
        $cacheKey = sprintf(
            'significantPriceChange_%s_onInterval_%s_count_%d',
            $entry->symbol->name(),
            $entry->averageOnInterval->value,
            $entry->intervalsCount
        );

        return $this->cache->get($cacheKey, fn () => $this->doHandle($entry));
    }

    private function doHandle(FindAveragePriceChange $entry): FindAveragePriceChangeResult
    {
        $symbol = $entry->symbol;
        $candleInterval = $entry->averageOnInterval;
        $intervalsCount = $entry->intervalsCount;

        $dateInterval = $entry->averageOnInterval->toDateInterval();

        $now = $this->clock->now();
        $dayStart = $now->setTime(0, 0);
        $secondsPassed = DateTimeHelper::dateIntervalToSeconds($now->diff($dayStart));

        $secondsInInterval = DateTimeHelper::dateIntervalToSeconds($dateInterval);
        $intervalsPassed = floor($secondsPassed / $secondsInInterval);

        $to = new DateTimeImmutable()->setTimestamp($dayStart->getTimestamp() + (int)$intervalsPassed * $secondsInInterval);
        $start = new DateTimeImmutable()->setTimestamp($to->getTimestamp() - $intervalsCount * $secondsInInterval);

        $candlesAll = $this->candlesProvider->getCandles(symbol: $symbol, interval: $candleInterval, from: $start, to: $to, limit: $intervalsCount);

        $absoluteDeltas = [];
        $percentDeltas = [];
        foreach ($candlesAll as $candleDto) {
            $priceDiffBetweenHighAndLow = $candleDto->highLowDiff();
            $open = $candleDto->open;

            $absoluteDeltas[] = $priceDiffBetweenHighAndLow;
            $percentDeltas[] = $priceDiffBetweenHighAndLow / $open;
        }

        return new FindAveragePriceChangeResult(
            new AveragePriceChange(
                $candleInterval,
                $intervalsCount,
                Percent::fromPart(array_sum($percentDeltas) / count($percentDeltas), false),
                array_sum($absoluteDeltas) / count($absoluteDeltas),
            )
        );
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
