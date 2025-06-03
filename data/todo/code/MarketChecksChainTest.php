<?php

declare(strict_types=1);

namespace data\todo\code;

use App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyChecksCollection;
use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Factory\TickerFactory;
use data\code\MarketBuyCheckInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Application\UseCase\Trading\MarketBuy\Checks\MarketBuyChecksCollection
 */
final class MarketChecksChainTest extends TestCase
{
    public function testCallAllChecks(): void
    {
        $order = new MarketBuyEntryDto(SymbolEnum::BNBUSDT, Side::Sell, 0.001, true);
        $ticker = TickerFactory::withEqualPrices(SymbolEnum::BTCUSDT, 100500);

        $firstCheck = $this->createMock(MarketBuyCheckInterface::class);
        $secondCheck = $this->createMock(MarketBuyCheckInterface::class);

        $collection = new MarketBuyChecksCollection($firstCheck, $secondCheck);

        $firstCheck->expects(self::once())->method('check')->with($order, $ticker);
        $secondCheck->expects(self::once())->method('check')->with($order, $ticker);

        $collection->check($order, $ticker);
    }
}
