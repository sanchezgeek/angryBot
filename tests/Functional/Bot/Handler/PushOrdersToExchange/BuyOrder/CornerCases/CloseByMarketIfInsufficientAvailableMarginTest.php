<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\VolumeHelper;
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
    private const USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY = PushBuyOrdersHandler::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT;
    private const TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT = PushBuyOrdersHandler::TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT;

    /**
     * @dataProvider doNotCloseByMarketCasesProvider
     */
    public function testDoNotCloseByMarket(Position $position, Ticker $ticker, float $coinSpotBalanceValue): void
    {
        $symbol = $position->symbol;
        $side = $position->side;

        $this->haveTicker($ticker);
        $this->havePosition($position);

        $buyOrder = new BuyOrder(10, $ticker->indexPrice /* trigger by indexPrice */, 0.003, 1, $side);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->haveSpotBalance($symbol, $coinSpotBalanceValue);
        $this->haveNoAvailableBalance($position, $buyOrder, $symbol, $side);

        // Assert
        $this->orderServiceMock->expects(self::never())->method('closeByMarket');
        $this->exchangeAccountServiceMock->expects(self::never())->method('interTransferFromContractToSpot');

        // Act
        ($this->handler)(new PushBuyOrders($symbol, $side));

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
        $symbol = $position->symbol;
        $side = $position->side;

        $this->haveTicker($ticker);
        $this->havePosition($position);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));
        $this->haveNoAvailableBalance($position, $buyOrder, $symbol, $side);

        $this->haveSpotBalance($symbol, 0);

        // Assert
        $this->orderServiceMock->expects(self::once())->method('closeByMarket')->with($position, $expectedCloseOrderVolume);

        $expectedProfit = PnlHelper::getPnlInUsdt($position, $ticker->lastPrice, $expectedCloseOrderVolume);
        $transferToSpotAmount = $expectedProfit * self::TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT;
        $this->exchangeAccountServiceMock
            ->expects(self::once())
            ->method('interTransferFromContractToSpot')
            ->with(
                $symbol->associatedCoin(),
                PriceHelper::round($transferToSpotAmount, 3),
            );

        // Act
        ($this->handler)(new PushBuyOrders($symbol, $side));

        // Assert
        self::seeBuyOrdersInDb(clone $buyOrder);
    }

    public function closeByMarketCasesProvider(): iterable
    {
        $needBuyOrderVolume = 0.003;
        $position = PositionFactory::short(self::SYMBOL, 30000);
        $minProfitPercent = self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY;

        $lastPrice = PnlHelper::getTargetPriceByPnlPercent($position, $minProfitPercent)->value();
        yield [
            'position' => $position,
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice - 1),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, $needBuyOrderVolume, 1, $position->side),
            'expectedCloseOrderVolume' => self::getExpectedVolumeToClose($needBuyOrderVolume, Price::float($lastPrice)->getPnlPercentFor($position)),
        ];

        $needBuyOrderVolume = 0.004;
        $lastPrice = PnlHelper::getTargetPriceByPnlPercent($position, $minProfitPercent + 100)->value();
        yield [
            'position' => $position,
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, $needBuyOrderVolume, 1, $position->side),
            'expectedCloseOrderVolume' => self::getExpectedVolumeToClose($needBuyOrderVolume, Price::float($lastPrice)->getPnlPercentFor($position)),
        ];
    }

    private static function getExpectedVolumeToClose(float $needBuyVolume, float $lastPriceCurrentPnlPercent): float
    {
        return VolumeHelper::forceRoundUp($needBuyVolume / ($lastPriceCurrentPnlPercent * 0.75 / 100));
    }

    public function haveNoAvailableBalance(Position $position, BuyOrder $buyOrder, Symbol $symbol, Side $side): void
    {
        $this->positionServiceMock
            ->method('marketBuy')
            ->with($position, $buyOrder->getVolume())
            ->willThrowException(
                CannotAffordOrderCost::forBuy($symbol, $side, $buyOrder->getVolume()),
            )
        ;
    }
}
