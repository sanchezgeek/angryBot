<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\TechnicalAnalysis\Application\MarketStructure;

use App\Domain\Trading\Enum\TimeFrame;
use App\TechnicalAnalysis\Application\MarketStructure\V2\ZigZagFinder;
use App\TechnicalAnalysis\Application\MarketStructure\V2\ZigZagService;
use App\TechnicalAnalysis\Application\MarketStructure\ZigZagFinder as ZigZagFinderV3;
use App\TechnicalAnalysis\Application\MarketStructure\ZigZagService as ZigZagServiceV3;
use App\TechnicalAnalysis\Domain\Dto\CandleDto;
use PHPUnit\Framework\TestCase;

final class ZigZagServiceTest extends TestCase
{
    public function testFinder(): void
    {
        $interval = TimeFrame::h4;
        $candles = json_decode(file_get_contents(__DIR__ . '/../candles_from_25_06-15.json'), true);

        $candles = array_map(static fn(array $candle) => CandleDto::fromArray($interval, $candle), $candles);

        $service = new ZigZagService(new ZigZagFinder());
        $points = $service->findZigZagPoints($candles);

        foreach ($points as $point) {
//            $candle = $candles[$point->candleIndex];

            echo "Индекс: {$point->getCandleIndex()}, ";
            echo "Дата: {$point->getUtcDatetime()}, ";
            echo "Цена: {$point->getPrice()}, ";
            echo "Тип: {$point->getType()}\n";
        }
    }

    public function testV3(): void
    {
        $interval = TimeFrame::h4;
        $candles = json_decode(file_get_contents(__DIR__ . '/../candles_from_25_06-15.json'), true);

        $candles = array_map(static fn(array $candle) => CandleDto::fromArray($interval, $candle), $candles);

        $service = new ZigZagServiceV3(new ZigZagFinderV3());
        $points = $service->findZigZagPoints($candles);

        foreach ($points as $point) {
//            $candle = $candles[$point->candleIndex];

            echo "Индекс: {$point->getCandleIndex()}, ";
            echo "Дата: {$point->getDatetime()}, ";
            echo "Цена: {$point->getPrice()}, ";
            echo "Тип: {$point->getType()}\n";
        }
    }
}
