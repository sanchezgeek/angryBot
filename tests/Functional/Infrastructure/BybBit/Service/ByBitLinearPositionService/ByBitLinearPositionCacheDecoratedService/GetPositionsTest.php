<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;

use function usleep;

/**
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService::getPositions
 */
final class GetPositionsTest extends ByBitLinearPositionCacheDecoratedServiceTestAbstract
{
    /**
     * @dataProvider positionsTestDataProvider
     */
    public function testCallInnerServiceWhenNoCachedValueExists(Symbol $symbol, array $innerServiceResult): void
    {
        $this->innerService->expects(self::once())->method('getPositions')->with($symbol)->willReturn($innerServiceResult);

        // Act
        $res = $this->service->getPositions($symbol);

        // Assert
        self::assertSame($innerServiceResult, $res);
    }

    /**
     * @dataProvider positionsTestDataProvider
     */
    public function testGetPositionsFromCache(Symbol $symbol, array $cachedPositions): void
    {
        $item = $this->cache->getItem($this->getPositionsCacheKey($symbol));
        $item->set($cachedPositions);
        $this->cache->save($item);

        $this->innerService->expects(self::never())->method('getPositions');

        // Act
        $res = $this->service->getPositions($symbol);

        // Assert
        self::assertEquals($cachedPositions, $res);
    }

    /**
     * @dataProvider positionsTestDataProvider
     */
    public function testCallInnerServiceWhenCachedValueInvalidated(Symbol $symbol, array $innerServiceResult): void
    {
        $oldPositions = [
            new Position(Side::Sell, $symbol, 29000, 1.1, 30900, 31000, 330, 330, 100),
            new Position(Side::Buy, $symbol, 30000, 0.5, 15000, 0, 150, 150, 100),
        ];

        $item = $this->cache->getItem($this->getPositionsCacheKey($symbol));
        $item->set($oldPositions);
        $item->expiresAfter(\DateInterval::createFromDateString('200 milliseconds'));
        $this->cache->save($item);

        $this->innerService->expects(self::once())->method('getPositions')->with($symbol)->willReturn($innerServiceResult);

        // Act
        usleep(300000);
        $res = $this->service->getPositions($symbol);

        // Assert
        self::assertNotEquals($oldPositions, $innerServiceResult);
        self::assertEquals($innerServiceResult, $res);
    }

    private function positionsTestDataProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;

        $position = new Position($side, $symbol, 30000, 1.1, 33000, 31000, 330, 330, 100);
        $oppositePosition = new Position($side->getOpposite(), $symbol, 33000, 0.5, 16500, 100500, 150, 150, 100);

        yield 'have position' => [$symbol, [$position]];
        yield 'have both positions' => [$symbol, [$position, $oppositePosition]];
        yield 'have no position' => [$symbol, []];
    }
}
