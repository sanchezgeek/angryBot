<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeService;

use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\V5\Request\Market\GetTickersRequest;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBit\MarketResponseBuilder;

final class GetTickerTest extends ByBitLinearExchangeServiceTestAbstract
{
    use ByBitV5ApiTester;

    public function testGetTicker(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;
        $category = self::ASSET_CATEGORY;

        $indexPrice = 30000;
        $markPrice = 29990;
        $lastPrice = 29980;

        $this->matchGet(
            new GetTickersRequest($category, $symbol),
            MarketResponseBuilder::ok($category)->withTicker($symbol, $indexPrice, $lastPrice, $markPrice)->build()
        );

        // Act
        $ticker = $this->service->ticker($symbol);

        // Assert
        self::assertEquals(
            new Ticker($symbol, $markPrice, $indexPrice, self::WORKER_DEBUG_HASH),
            $ticker
        );
    }
}
