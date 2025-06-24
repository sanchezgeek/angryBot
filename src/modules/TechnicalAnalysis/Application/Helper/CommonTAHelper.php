<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\Helper;

use App\Helper\Json;
use App\Helper\OutputHelper;
use JetBrains\PhpStorm\NoReturn;

final class CommonTAHelper
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
}
