<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;

use App\Tests\Factory\Position\PositionBuilder;

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
            PositionBuilder::short()->entry(15000)->size(1.1)->build(),
            PositionBuilder::long()->entry(10000)->size(1.1)->build(),
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

        $position = PositionBuilder::bySide($side)->entry(60000)->size(1.1)->build();
        $oppositePosition = PositionBuilder::bySide($side->getOpposite())->entry(33000)->size(0.7)->build();

        yield 'have position' => [$symbol, [$position]];
        yield 'have both positions' => [$symbol, [$position, $oppositePosition]];
        yield 'have no position' => [$symbol, []];
    }
}
