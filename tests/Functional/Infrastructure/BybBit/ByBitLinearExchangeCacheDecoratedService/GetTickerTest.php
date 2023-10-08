<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeCacheDecoratedService;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;

use function usleep;

final class GetTickerTest extends ByBitLinearExchangeCacheDecoratedServiceTestAbstract
{
    public function testCallInnerServiceWhenNoCachedValueExists(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;
        $ticker = new Ticker($symbol, 30000, 29990, self::WORKER_DEBUG_HASH);

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
        $symbol = Symbol::BTCUSDT;
        $ticker = new Ticker($symbol, 30000, 29990, self::WORKER_DEBUG_HASH);
        $item = $this->cache->getItem($this->getTickerCacheKey($symbol));
        $item->set($ticker);
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
        $symbol = Symbol::BTCUSDT;
        $oldCachedTicker = new Ticker($symbol, 30000, 29990, self::WORKER_DEBUG_HASH);
        $ticker = new Ticker($symbol, 31000, 30990, self::WORKER_DEBUG_HASH);

        $item = $this->cache->getItem($this->getTickerCacheKey($symbol));
        $item->set($oldCachedTicker);
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
}
