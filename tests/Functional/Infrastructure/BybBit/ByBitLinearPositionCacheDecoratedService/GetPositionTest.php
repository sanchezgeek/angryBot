<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\ByBitLinearPositionCacheDecoratedService;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function sleep;
use function usleep;

/**
 * @covers \App\Infrastructure\ByBit\ByBitLinearPositionCacheDecoratedService::getPosition
 */
final class GetPositionTest extends ByBitLinearPositionCacheDecoratedServiceTestAbstract
{
    /**
     * @dataProvider positionValueOptionProvider
     */
    public function testCallInnerServiceWhenNoCachedValueExists(
        Symbol $symbol,
        Side $side,
        ?Position $position
    ): void {
        // Arrange
        if ($position) {
            $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new PositionUpdated($position));
        } else {
            $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        }

        $this->innerService->expects(self::once())->method('getPosition')->with($symbol, $side)->willReturn($position);

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
        ?Position $position
    ): void {
        // Arrange
        $item = $this->cache->getItem($this->getPositionCacheItemKey($symbol, $side));
        $item->set($position);
        $this->cache->save($item);

        $this->eventDispatcherMock->expects(self::never())->method('dispatch');

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
        ?Position $position
    ): void {
        // Arrange
        $oldCachedPosition = new Position($side, $symbol, 29000, 1.1, 30900, 31000, 330, 100);

        $item = $this->cache->getItem($this->getPositionCacheItemKey($symbol, $side));
        $item->set($oldCachedPosition);
        $item->expiresAfter(\DateInterval::createFromDateString('200 milliseconds'));
        $this->cache->save($item);

        if ($position) {
            $this->eventDispatcherMock->expects(self::once())->method('dispatch')->with(new PositionUpdated($position));
        } else {
            $this->eventDispatcherMock->expects(self::never())->method('dispatch');
        }

        $this->innerService->expects(self::once())->method('getPosition')->with($symbol, $side)->willReturn($position);

        // Act
        usleep(300000);
        $res = $this->service->getPosition($symbol, $side);

        // Assert
        self::assertNotEquals($oldCachedPosition, $position);
        self::assertSame($position, $res);
    }

    private function positionValueOptionProvider(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;

        yield 'have position'    => [$symbol, $side, new Position($side, $symbol, 30000, 1.1, 33000, 31000, 330, 100)];
        yield 'have no position' => [$symbol, $side, null];
    }
}
