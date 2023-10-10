<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

use function usleep;

/**
 * @covers \App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService::getOppositePosition
 */
final class GetOppositePositionTest extends ByBitLinearPositionCacheDecoratedServiceTestAbstract
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->innerService->expects(self::never())->method('getOppositePosition');
    }

    /**
     * @dataProvider oppositePositionValueOptionProvider
     */
    public function testCallInnerServiceWhenNoCachedValueExists(
        Symbol $symbol,
        Side $side,
        ?Position $oppositePosition
    ): void {
        // PreArrange
        $symbol = $oppositePosition->symbol ?? $symbol;
        $side = $oppositePosition->side ?? $side;
        $position = new Position($side->getOpposite(), $symbol, 25000, 1.1, 30900, 31000, 330, 100);

        // Arrange
        if ($oppositePosition) {
            $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new PositionUpdated($oppositePosition));
        } else {
            $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        }

        $this->innerService->expects(self::once())->method('getPosition')->with($symbol, $side)->willReturn($oppositePosition);

        // Act
        $res = $this->service->getOppositePosition($position);

        // Assert
        self::assertSame($oppositePosition, $res);
    }

    /**
     * @dataProvider oppositePositionValueOptionProvider
     */
    public function testGetOppositePositionFromCache(
        Symbol $symbol,
        Side $side,
        ?Position $oppositePosition
    ): void {
        // PreArrange
        $symbol = $oppositePosition->symbol ?? $symbol;
        $side = $oppositePosition->side ?? $side;
        $position = new Position($side->getOpposite(), $symbol, 25000, 1.1, 30900, 31000, 330, 100);

        // Arrange
        $item = $this->cache->getItem($this->getPositionCacheItemKey($symbol, $side));
        $item->set($oppositePosition);
        $this->cache->save($item);

        $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        $this->innerService->expects(self::never())->method('getPosition');

        // Act
        $res = $this->service->getOppositePosition($position);

        // Assert
        self::assertEquals($oppositePosition, $res);
    }

    /**
     * @dataProvider oppositePositionValueOptionProvider
     */
    public function testCallInnerServiceWhenCachedValueInvalidated(
        Symbol $symbol,
        Side $side,
        ?Position $oppositePosition
    ): void {
        // PreArrange
        $symbol = $oppositePosition->symbol ?? $symbol;
        $side = $oppositePosition->side ?? $side;
        $position = new Position($side->getOpposite(), $symbol, 25000, 1.1, 30900, 31000, 330, 100);

        // Arrange
        $oldCachedOpposite = new Position($side, $symbol, 21000, 1.1, 30900, 31000, 330, 100);

        $item = $this->cache->getItem($this->getPositionCacheItemKey($symbol, $side));
        $item->set($oldCachedOpposite);
        $item->expiresAfter(\DateInterval::createFromDateString('200 milliseconds'));
        $this->cache->save($item);

        if ($oppositePosition) {
            $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new PositionUpdated($oppositePosition));
        } else {
            $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        }

        $this->innerService->expects(self::once())->method('getPosition')->with($symbol, $side)->willReturn($oppositePosition);

        // Act
        usleep(300000);
        $res = $this->service->getOppositePosition($position);

        // Assert
        self::assertNotEquals($oldCachedOpposite, $oppositePosition);
        self::assertSame($oppositePosition, $res);
    }

    private function oppositePositionValueOptionProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;

        yield 'have position'    => [$symbol, $side, new Position($side, $symbol, 30000, 1.1, 33000, 31000, 330, 100)];
        yield 'have no position' => [$symbol, $side, null];
    }
}
