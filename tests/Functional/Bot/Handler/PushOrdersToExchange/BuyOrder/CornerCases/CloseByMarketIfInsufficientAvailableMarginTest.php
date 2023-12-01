<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Infrastructure\ByBit\Service\Exception\Trade\CannotAffordOrderCost;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler
 */
final class CloseByMarketIfInsufficientAvailableMarginTest extends PushBuyOrdersCornerCasesTestAbstract
{
    /**
     * @dataProvider doNotCloseByMarketCasesProvider
     */
    public function testDoNotCloseByMarket(Ticker $ticker, Position $position, float $coinSpotBalanceValue): void
    {
        $this->haveTicker($ticker);
        $this->havePosition($position);

        $buyOrder = new BuyOrder(10, $ticker->indexPrice /* trigger by indexPrice */, 0.003, 1, $position->side);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->positionServiceMock->expects(self::once())->method('marketBuy')->with($position, $buyOrder->getVolume())->willThrowException(
            CannotAffordOrderCost::forBuy($position->symbol, $position->side, $buyOrder->getVolume()),
        );

        $this->haveSpotBalance($position->symbol, $coinSpotBalanceValue);

        // Assert
        $this->orderServiceMock->expects(self::never())->method('closeByMarket');

        // Act
        ($this->handler)(new PushBuyOrders($position->symbol, $position->side));

        // Assert
        self::seeBuyOrdersInDb(clone $buyOrder);
    }

    public function doNotCloseByMarketCasesProvider(): iterable
    {
        yield [
            'ticker' => TickerFactory::create(self::SYMBOL, 29720, 29710, 29701),
            'position' => PositionFactory::short(self::SYMBOL, 30000),
            'coinSpotBalanceValue' => 0,
        ];

        yield [
            'ticker' => TickerFactory::create(self::SYMBOL, 29720, 29710, 29700),
            'position' => PositionFactory::short(self::SYMBOL, 30000),
            'coinSpotBalanceValue' => self::USE_SPOT_IF_BALANCE_GREATER_THAN + 1,
        ];

        yield [
            'ticker' => TickerFactory::create(self::SYMBOL, 28720, 28710, 28700),
            'position' => PositionFactory::short(self::SYMBOL, 30000),
            'coinSpotBalanceValue' => self::USE_SPOT_IF_BALANCE_GREATER_THAN + 1,
        ];
    }

    /**
     * @dataProvider closeByMarketCasesProvider
     */
    public function testCloseByMarket(Position $position, Ticker $ticker, BuyOrder $buyOrder, float $expectedCloseOrderVolume): void
    {
        $this->haveTicker($ticker);
        $this->havePosition($position);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->positionServiceMock->expects(self::once())->method('marketBuy')->with($position, $buyOrder->getVolume())->willThrowException(
            CannotAffordOrderCost::forBuy($position->symbol, $position->side, $buyOrder->getVolume()),
        );

        $this->haveSpotBalance($position->symbol, 0);

        // Assert
        $this->orderServiceMock->expects(self::once())->method('closeByMarket')->with($position, $expectedCloseOrderVolume);

        // Act
        ($this->handler)(new PushBuyOrders($position->symbol, $position->side));

        // Assert
        self::seeBuyOrdersInDb(clone $buyOrder);
    }

    public function closeByMarketCasesProvider(): iterable
    {
        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29720, 29710, 29700),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
            'expectedCloseOrderVolume' => 0.003,
        ];

        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29420, 29410, 29401),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
            'expectedCloseOrderVolume' => 0.003,
        ];

        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29420, 29410, 29400),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
            'expectedCloseOrderVolume' => 0.002,
        ];

        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29120, 29110, 29101),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
            'expectedCloseOrderVolume' => 0.002,
        ];

        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 29120, 29110, 29099),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
            'expectedCloseOrderVolume' => 0.001,
        ];

        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, 28120, 28110, 28099),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
            'expectedCloseOrderVolume' => 0.001,
        ];
    }
}