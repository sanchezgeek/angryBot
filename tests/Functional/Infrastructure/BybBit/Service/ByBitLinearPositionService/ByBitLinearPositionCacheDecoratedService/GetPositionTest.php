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
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService::getPosition
 */
final class GetPositionTest extends ByBitLinearPositionCacheDecoratedServiceTestAbstract
{
    /**
     * @dataProvider positionValueOptionProvider
     */
    public function testCallInnerServiceWhenNoCachedValueExists(
        Symbol $symbol,
        Side $side,
        ?Position $position,
        array $innerServiceResult
    ): void {
//        if ($position) $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new PositionUpdated($position)); else $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        $this->innerService->expects(self::once())->method('getPositions')->with($symbol)->willReturn($innerServiceResult);

        // Act
        $res = $this->service->getPosition($symbol, $side);

        // Assert
        self::assertSame($position, $res);
    }

    /**
     * @dataProvider positionValueOptionProvider
     */
    public function testGetPositionFromCache(
        Symbol $symbol,
        Side $side,
        ?Position $position,
        array $cachedFromInnerService
    ): void {
        $item = $this->cache->getItem($this->getPositionsCacheKey($symbol));
        $item->set($cachedFromInnerService);
        $this->cache->save($item);

//        $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        $this->innerService->expects(self::never())->method('getPosition');

        // Act
        $res = $this->service->getPosition($symbol, $side);

        // Assert
        self::assertEquals($position, $res);
    }

    /**
     * @dataProvider positionValueOptionProvider
     */
    public function testCallInnerServiceWhenCachedValueInvalidated(
        Symbol $symbol,
        Side $side,
        ?Position $position,
        array $innerServiceResult
    ): void {
        $oldCachedPosition = PositionBuilder::bySide($side)->entry(15000)->size(0.1)->build();

        $item = $this->cache->getItem($this->getPositionsCacheKey($symbol));
        $item->set([$oldCachedPosition]);
        $item->expiresAfter(\DateInterval::createFromDateString('200 milliseconds'));
        $this->cache->save($item);

//        if ($position) $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new PositionUpdated($position)); else $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        $this->innerService->expects(self::once())->method('getPositions')->with($symbol)->willReturn($innerServiceResult);

        // Act
        usleep(300000);
        $res = $this->service->getPosition($symbol, $side);

        // Assert
        self::assertNotEquals($oldCachedPosition, $position);
        self::assertEquals($position, $res);
    }

    private function positionValueOptionProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;

        $position = PositionBuilder::bySide($side)->entry(62000)->size(1.3)->build();
        $oppositePosition = PositionBuilder::bySide($side->getOpposite())->entry(31000)->size(0.9)->build();

        yield 'have position' => [$symbol, $side, $position, [$position]];
        yield 'have both positions' => [$symbol, $side, $position, [$position, $oppositePosition]];
        yield 'have no position' => [$symbol, $side, null, []];
    }
}
