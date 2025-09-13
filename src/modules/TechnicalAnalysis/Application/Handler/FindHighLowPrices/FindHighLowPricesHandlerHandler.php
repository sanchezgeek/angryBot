<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Handler\FindHighLowPrices;

use App\Domain\Trading\Enum\TimeFrame;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterAutowiredArgument;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameterEvaluations;
use App\TechnicalAnalysis\Application\Contract\FindHighLowPricesHandlerInterface;
use App\TechnicalAnalysis\Application\Contract\Query\FindHighLowPrices;
use App\TechnicalAnalysis\Application\Contract\Query\FindHighLowPricesResult;
use App\TechnicalAnalysis\Application\Service\Candles\PreviousCandlesProvider;

final readonly class FindHighLowPricesHandlerHandler implements FindHighLowPricesHandlerInterface, AppDynamicParametersProviderInterface
{
    #[AppDynamicParameter(group: 'ta', name: 'highLowPrices')]
    public function handle(
        #[AppDynamicParameterEvaluations(defaultValueProvider: FindHighLowPricesEntryEvaluationProvider::class, skipUserInput: true)]
        FindHighLowPrices $entry
    ): FindHighLowPricesResult {
        $cacheKey = sprintf('HL_prices_%s', $entry->symbol->name());

        return $this->cache->get($cacheKey, fn () => $this->doHandle($entry));
    }

    private function doHandle(FindHighLowPrices $entry): FindHighLowPricesResult
    {
        $symbol = $entry->symbol;

        $candles = $this->candlesProvider->getPreviousCandles($symbol, TimeFrame::M1, 1000);

        $prices = [];
        foreach ($candles as $candle) {
            $prices[] = $candle->open;
            $prices[] = $candle->high;
            $prices[] = $candle->low;
        }

        return new FindHighLowPricesResult(
            $symbol->makePrice(max($prices)),
            $symbol->makePrice(min($prices))
        );
    }

    public function __construct(
        #[AppDynamicParameterAutowiredArgument]
        private PreviousCandlesProvider $candlesProvider,

        #[AppDynamicParameterAutowiredArgument]
        private FindHighLowPricesCache $cache,
    ) {
    }
}
