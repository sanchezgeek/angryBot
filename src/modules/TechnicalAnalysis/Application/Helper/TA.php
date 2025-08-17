<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Helper;

use App\Domain\Trading\Enum\TimeFrame;
use App\Helper\Json;
use App\Helper\OutputHelper;
use App\Infrastructure\DependencyInjection\GetServiceHelper;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\TechnicalAnalysis\Application\Handler\CalcAverageTrueRange\CalcAverageTrueRangeResult;
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
        /** @var TAToolsProviderInterface $taProvider */
        $taProvider = GetServiceHelper::getService(TAToolsProviderInterface::class);

        return $taProvider->create($symbol, $timeFrame)->atr($period);
    }
}
