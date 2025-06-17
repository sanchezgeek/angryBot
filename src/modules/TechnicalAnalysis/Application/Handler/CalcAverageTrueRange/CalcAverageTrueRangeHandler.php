<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange;

use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\TechnicalAnalysis\Application\Contract\CalcAverageTrueRangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\TechnicalAnalysis\Application\Service\Calculate\ATRCalculator;
use App\TechnicalAnalysis\Application\Service\Candles\PreviousCandlesProvider;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;

/**
 * @todo | ATR | research
 * Результат можно использовать для поиска интервала на полученном наборе kniles и получения процента от этого change и дальнейшего принятия решения
 * либо просто пропорционально
 * критерии пробоя / разворота: подтверждено объёмами (или  наоборот). чтобы понять в какую сторону открывать
 *
 *
 *
 * Ещё нужен какой-то определятор повышена ли в моменте (сегодня или за какой-то период) волатильность
 */
final readonly class CalcAverageTrueRangeHandler implements CalcAverageTrueRangeHandlerInterface, AppDynamicParametersProviderInterface
{
    #[AppDynamicParameter(group: 'priceChange', name: 'ATR')]
    public function handle(
        #[AppDynamicParameterEvaluations(defaultValueProvider: CalcAverageTrueRangeEntryEvaluationProvider::class, skipUserInput: true)]
        CalcAverageTrueRange $entry
    ): CalcAverageTrueRangeResult {
        $cacheKey = sprintf(
            'averageTrueRange_for_%s_onInterval_%s_intervalsBack_%d',
            $entry->symbol->name(),
            $entry->interval->value,
            $entry->period
        );

        return $this->cache->get($cacheKey, fn () => $this->doHandle($entry));
    }

    private function doHandle(CalcAverageTrueRange $entry): CalcAverageTrueRangeResult
    {
        $symbol = $entry->symbol;
        $candleInterval = $entry->interval;
        $period = $entry->period;

        $candles = $this->candlesProvider->getPreviousCandles($symbol, $candleInterval, $period + 1, true);

        $nAtr = ATRCalculator::calculate($period, $candles);

        // @todo | а надо ли фильтровать? Если за указанный период произошло что-то исключительное, значит может произойти снова и должно быть учтено

        $refPrice = $candles[array_key_last($candles)]->open;

        return new CalcAverageTrueRangeResult(
            new AveragePriceChange(
                $candleInterval,
                $period,
                $nAtr,
                Percent::fromPart($nAtr / $refPrice, false),
                $refPrice
            )
        );
    }

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private PreviousCandlesProvider $candlesProvider,

        #[AppDynamicParameterAutowiredArgument]
        private AverageTrueRangeCache $cache,
    ) {
    }
}
