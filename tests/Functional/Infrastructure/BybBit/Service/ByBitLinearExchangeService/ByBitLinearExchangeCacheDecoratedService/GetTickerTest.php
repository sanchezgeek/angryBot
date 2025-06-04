<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\ByBitLinearExchangeCacheDecoratedService;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Infrastructure\ByBit\Service\CacheDecorated\Dto\CachedTickerDto;
use App\Tests\Factory\TickerFactory;

use function usleep;

/**
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService::ticker
 */
final class GetTickerTest extends ByBitLinearExchangeCacheDecoratedServiceTestAbstract
{
    public function testCallInnerServiceWhenNoCachedValueExists(): void
    {
        // Arrange
        $symbol = SymbolEnum::BTCUSDT;
        $ticker = TickerFactory::create($symbol, 29990, 30000);

        $this->innerService->expects(self::once())->method('ticker')->with($symbol)->willReturn($ticker);
        $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new TickerUpdated($ticker));

        // Act
        $res = $this->service->ticker($symbol);

        // Assert
        self::assertSame($ticker, $res);
    }

    public function testGetPositionFromCache(): void
    {
        // Arrange
        $symbol = SymbolEnum::BTCUSDT;
        $ticker = TickerFactory::create($symbol, 29990, 30000);
        $item = $this->cache->getItem($this->getTickerCacheKey($symbol));
        $item->set(self::cachedTickerItemValue($ticker));
        $this->cache->save($item);

        $this->innerService->expects(self::never())->method('ticker');
        $this->eventDispatcherMock->expects(self::never())->method('dispatch');

        // Act
        $res = $this->service->ticker($symbol);

        // Assert
        self::assertEquals($ticker, $res);
    }

    public function testCallInnerServiceWhenCachedValueInvalidated(): void
    {
        // Arrange
        $symbol = SymbolEnum::BTCUSDT;
        $oldCachedTicker = TickerFactory::create($symbol,  29990, 30000);
        $ticker = TickerFactory::create($symbol,  30990, 31000);

        $item = $this->cache->getItem($this->getTickerCacheKey($symbol));
        $item->set(self::cachedTickerItemValue($oldCachedTicker));
        $item->expiresAfter(\DateInterval::createFromDateString('200 milliseconds'));
        $this->cache->save($item);

        $this->innerService->expects(self::once())->method('ticker')->with($symbol)->willReturn($ticker);
        $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new TickerUpdated($ticker));


        // Act
        usleep(300000);
        $res = $this->service->ticker($symbol);

        // Assert
        self::assertNotEquals($oldCachedTicker, $ticker);
        self::assertSame($ticker, $res);
    }

    private static function cachedTickerItemValue(Ticker $ticker): CachedTickerDto
    {
        return new CachedTickerDto($ticker, 'test');
    }
}
