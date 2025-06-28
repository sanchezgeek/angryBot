<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\TechnicalAnalysis\Application\Contract\CalcAverageTrueRangeHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\TechnicalAnalysis\Application\Helper\TraderInput;
use App\TechnicalAnalysis\Application\Service\Candles\PreviousCandlesProvider;
use App\TechnicalAnalysis\Domain\Dto\AveragePriceChange;
use App\Trading\Domain\Symbol\SymbolInterface;
use Timirey\Trader\TraderService;

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
    #[AppDynamicParameter(group: 'ta', name: 'ATR')]
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
        $atr = null;
        $refPrice = null;

        while ($atr === null && $period >= 2) {
            try {
                [$atr, $refPrice] = $this->getForPeriod($period, $symbol, $candleInterval);
            } catch (\BadFunctionCallException $e) {
                $period--;
            }
        }

        if ($atr === null) {
            $candles = $this->candlesProvider->getPreviousCandles($symbol, $candleInterval, 1, true);
            $atr = $candles[0]->highLowDiff();
            $refPrice = $candles[array_key_last($candles)]->close;
        }

        return new CalcAverageTrueRangeResult(
            new AveragePriceChange(
                $candleInterval,
                $period,
                $atr,
                Percent::fromPart($atr / $refPrice, false),
                $refPrice
            )
        );
    }

    private function getForPeriod(int $period, SymbolInterface $symbol, CandleIntervalEnum $candleInterval): array
    {
        $candlesCount = $period + 1;
        $candles = $this->candlesProvider->getPreviousCandles($symbol, $candleInterval, $candlesCount, true);

        // @todo | ta | !!!обёртка над TraderService!!!
        $multiplier = 10000;
        $input = new TraderInput($multiplier, ...$candles);

        $res = new TraderService()->atr($input->highPrices, $input->lowPrices, $input->closePrices, $period);
        $atr = end($res) / $multiplier;

        // @todo | some strategy to get basePrice?
        $refPrice = $candles[array_key_last($candles)]->close;

        return [$atr, $refPrice];
    }

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private PreviousCandlesProvider $candlesProvider,

        #[AppDynamicParameterAutowiredArgument]
        private AverageTrueRangeCache $cache,
    ) {
    }
}
