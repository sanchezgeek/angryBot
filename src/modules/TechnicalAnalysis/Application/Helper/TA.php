<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Helper;

use App\Domain\Price\SymbolPrice;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Helper\Json;
use App\Helper\OutputHelper;
use App\Infrastructure\DependencyInjection\GetServiceHelper;
use App\TechnicalAnalysis\Application\Contract\Query\GetInstrumentAgeResult;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;
use App\TechnicalAnalysis\Domain\Dto\Ath\PricePartOfAth;
use App\TechnicalAnalysis\Domain\Dto\HighLow\HighLowPrices;
use App\Trading\Domain\Symbol\SymbolInterface;
use JetBrains\PhpStorm\NoReturn;

final class TA
{
    #[NoReturn] public static function candlesDebug(array $candles): void
    {
        OutputHelper::print($candles);
        OutputHelper::print('', sprintf('Count: %s', count($candles)));
        die;
    }

    public static function getCandlesDebug(array $candles): array
    {
        return [
            'candles' => Json::encodePretty($candles),
            'count' => count($candles),
        ];
    }

    public static function atr(SymbolInterface $symbol, TimeFrame $timeFrame, int $period): CalcAverageTrueRangeResult
    {
        return self::getTaToolsProvider()->create($symbol, $timeFrame)->atr($period);
    }

    public static function allTimeHighLow(SymbolInterface $symbol): HighLowPrices
    {
        return self::getTaToolsProvider()->create($symbol)->highLowPrices();
    }

    public static function pricePartOfAth(SymbolInterface $symbol, SymbolPrice $price): Percent
    {
        $allTimeHighLow = TA::allTimeHighLow($symbol);
        $currentPriceDeltaFromLow = $price->value() - $allTimeHighLow->low->value();

        return Percent::fromPart($currentPriceDeltaFromLow / $allTimeHighLow->delta(), false);
    }

    public static function pricePartOfAthExtended(SymbolInterface $symbol, SymbolPrice $price): PricePartOfAth
    {
        $allTimeHighLow = TA::allTimeHighLow($symbol);
        $currentPriceDeltaFromLow = $price->deltaWith($allTimeHighLow->low);
        $partOfAthDelta = abs($currentPriceDeltaFromLow) / $allTimeHighLow->delta();

        return match (true) {
            $price->lessThan($allTimeHighLow->low) => PricePartOfAth::overLow($allTimeHighLow, $partOfAthDelta),
            $price->greaterThan($allTimeHighLow->high) => PricePartOfAth::overHigh($allTimeHighLow, $partOfAthDelta),
            default => PricePartOfAth::inTheBetween($allTimeHighLow, $partOfAthDelta)
        };
    }

    public static function instrumentAge(SymbolInterface $symbol): GetInstrumentAgeResult
    {
        return self::getTaToolsProvider()->create($symbol)->instrumentAge();
    }

    private static function getTaToolsProvider(): TAToolsProviderInterface
    {
        return GetServiceHelper::getService(TAToolsProviderInterface::class);
    }
}
