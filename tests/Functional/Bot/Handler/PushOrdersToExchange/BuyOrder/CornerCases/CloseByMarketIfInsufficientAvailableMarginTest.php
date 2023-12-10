<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Stop\Helper\PnlHelper;
use App\Infrastructure\ByBit\Service\Exception\Trade\CannotAffordOrderCost;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler
 */
final class CloseByMarketIfInsufficientAvailableMarginTest extends PushBuyOrdersCornerCasesTestAbstract
{
    private const USE_SPOT_IF_BALANCE_GREATER_THAN = PushBuyOrdersHandler::USE_SPOT_IF_BALANCE_GREATER_THAN;
    private const USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY = PushBuyOrdersHandler::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY;

    /**
     * @dataProvider doNotCloseByMarketCasesProvider
     */
    public function testDoNotCloseByMarket(Position $position, Ticker $ticker, float $coinSpotBalanceValue): void
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
        $position = PositionFactory::short(self::SYMBOL, 30000);
        $lastPrice = PnlHelper::getTargetPriceByPnlPercent($position, self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY)->value();

        yield 'lastPrice pnl less than min required' => [
            'position' => $position,
            'ticker' => TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice + 1),
            'coinSpotBalanceValue' => 0,
        ];

        yield 'lastPrice pnl greater than min required, but spot balance available' => [
            'position' => $position,
            'ticker' => TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice - 1),
            'coinSpotBalanceValue' => self::USE_SPOT_IF_BALANCE_GREATER_THAN + 1,
        ];

        yield 'lastPrice pnl over min required, but spot balance available' => [
            'position' => $position,
            'ticker' => TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice - 1),
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
        $position = PositionFactory::short(self::SYMBOL, 30000);

        $lastPrice = PnlHelper::getTargetPriceByPnlPercent($position, self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY)->value();
        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
//            'expectedCloseOrderVolume' => 0.003, # for now 0.001 in all cases
            'expectedCloseOrderVolume' => 0.001,
        ];

        $percent = 200;
        $lastPrice = PnlHelper::getTargetPriceByPnlPercent($position, $percent)->value();
        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice + 1),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
//            'expectedCloseOrderVolume' => 0.003, # for now 0.001 in all cases
            'expectedCloseOrderVolume' => 0.001,
        ];

        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
//            'expectedCloseOrderVolume' => 0.002, # for now 0.001 in all cases
            'expectedCloseOrderVolume' => 0.001,
        ];

        $percent = 300;
        $lastPrice = PnlHelper::getTargetPriceByPnlPercent($position, $percent)->value();
        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice + 1),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
//            'expectedCloseOrderVolume' => 0.002, # for now 0.001 in all cases
            'expectedCloseOrderVolume' => 0.001,
        ];

        $percent = 400;
        $lastPrice = PnlHelper::getTargetPriceByPnlPercent($position, $percent)->value();
        yield [
            'position' => $position = PositionFactory::short(self::SYMBOL, 30000),
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, 0.003, 1, $position->side),
            'expectedCloseOrderVolume' => 0.001,
        ];
    }
}
