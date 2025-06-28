<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindAveragePriceChange;

use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\TechnicalAnalysis\Application\Contract\FindAveragePriceChangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;
use App\TechnicalAnalysis\Application\Service\Candles\PreviousCandlesProvider;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;

final readonly class FindAveragePriceChangeHandler implements FindAveragePriceChangeHandlerInterface, AppDynamicParametersProviderInterface
{
    #[AppDynamicParameter(group: 'ta', name: 'averagePriceChange')]
    public function handle(
        #[AppDynamicParameterEvaluations(defaultValueProvider: FindAveragePriceChangeEntryEvaluationProvider::class, skipUserInput: true)]
        FindAveragePriceChange $entry
    ): FindAveragePriceChangeResult {
        $cacheKey = sprintf(
            'significantPriceChange_%s_onInterval_%s_count_%d_useUnclosedCandle_%s',
            $entry->symbol->name(),
            $entry->averageOnInterval->value,
            $entry->intervalsCount,
            $entry->useCurrentUnfinishedIntervalForCalc ? 'true' : 'false'
        );

        return $this->cache->get($cacheKey, fn () => $this->doHandle($entry));
    }

    private function doHandle(FindAveragePriceChange $entry): FindAveragePriceChangeResult
    {
        $symbol = $entry->symbol;
        $candleInterval = $entry->averageOnInterval;
        $intervalsCount = $entry->intervalsCount;

        $candles = $this->candlesProvider->getPreviousCandles($symbol, $candleInterval, $intervalsCount, $entry->useCurrentUnfinishedIntervalForCalc);

        $absoluteDeltas = [];
        $percentDeltas = [];
        foreach ($candles as $candleDto) {
            $priceDiffBetweenHighAndLow = $candleDto->highLowDiff();
            $open = $candleDto->open;

            $absoluteDeltas[] = $priceDiffBetweenHighAndLow;
            $percentDeltas[] = $priceDiffBetweenHighAndLow / $open;
        }

        return new FindAveragePriceChangeResult(
            new AveragePriceChange(
                $candleInterval,
                $intervalsCount,
                array_sum($absoluteDeltas) / count($absoluteDeltas),
                Percent::fromPart(array_sum($percentDeltas) / count($percentDeltas), false),
            )
        );
    }

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private PreviousCandlesProvider $candlesProvider,

        #[AppDynamicParameterAutowiredArgument]
        private AveragePriceChangeCache $cache,
    ) {
    }
}
