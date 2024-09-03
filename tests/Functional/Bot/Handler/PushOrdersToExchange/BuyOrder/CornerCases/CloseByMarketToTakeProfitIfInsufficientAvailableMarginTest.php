<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Coin\CoinAmount;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Price\Price;
use App\Domain\Stop\Helper\PnlHelper;
use App\Helper\VolumeHelper;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;

/**
 * @covers \App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler
 */
final class CloseByMarketToTakeProfitIfInsufficientAvailableMarginTest extends PushBuyOrdersCornerCasesTestAbstract
{
    private const USE_SPOT_IF_BALANCE_GREATER_THAN = PushBuyOrdersHandler::USE_SPOT_IF_BALANCE_GREATER_THAN;
    private const USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY = PushBuyOrdersHandler::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT;
    private const TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT = PushBuyOrdersHandler::TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT;
    private const REOPEN_DISTANCE = 100;
    private const SPOT_TRANSFER_ON_BUY_MULTIPLIER = PushBuyOrdersHandler::SPOT_TRANSFER_ON_BUY_MULTIPLIER;

    private OrderCostCalculator $orderCostCalculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderCostCalculator = self::getContainer()->get(OrderCostCalculator::class);

        # @todo | for now to prevent MarketBuyHandler "buyIsSafe" checks
        $marketBuyHandler = self::getContainer()->get(MarketBuyHandler::class); /** @var MarketBuyHandler $marketBuyHandler */
        $marketBuyHandler->setSafeLiquidationPriceDistance(500);
    }

    /**
     * @dataProvider doNotCloseByMarketCasesProvider
     */
    public function testDoNotCloseByMarket(Position $position, Ticker $ticker, float $availableSpotBalance): void
    {
        $symbol = $position->symbol;
        $side = $position->side;

        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $position);

        $buyOrder = new BuyOrder(10, $ticker->indexPrice /* trigger by indexPrice */, 0.003, $side);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->haveAvailableSpotBalance($symbol, $availableSpotBalance);
        $this->haveContractWalletBalanceAllUsedToOpenPosition($position);
        $this->expectsToMakeApiCalls(...self::cannotAffordBuyApiCallExpectations($symbol, [$buyOrder]));

        // Assert
        if ($availableSpotBalance > 0) {
            $buyCost = $this->orderCostCalculator->totalBuyCost(
                new ExchangeOrder($symbol, $buyOrder->getVolume(), $ticker->lastPrice), $position->leverage, $buyOrder->getPositionSide()
            );
            $buyCost = $buyCost->value() * self::SPOT_TRANSFER_ON_BUY_MULTIPLIER;
            $this->expectsInterTransferFromSpotToContract(new CoinAmount($symbol->associatedCoin(), $buyCost));
        }

        // Act
        ($this->handler)(new PushBuyOrders($symbol, $side));

        // Assert
        self::seeBuyOrdersInDb(clone $buyOrder);
    }

    public function doNotCloseByMarketCasesProvider(): iterable
    {
        $position = PositionFactory::short(self::SYMBOL, 30000);
        $lastPrice = PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY)->value();

        yield 'lastPrice pnl less than min required' => [
            'position' => $position,
            'ticker' => TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice + 1),
            'availableSpotBalance' => 0,
        ];

        yield 'lastPrice pnl greater than min required, but spot balance available' => [
            'position' => $position,
            'ticker' => TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice - 1),
            'availableSpotBalance' => self::USE_SPOT_IF_BALANCE_GREATER_THAN + 1,
        ];

        yield 'lastPrice pnl over min required, but spot balance available' => [
            'position' => $position,
            'ticker' => TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice - 1),
            'availableSpotBalance' => self::USE_SPOT_IF_BALANCE_GREATER_THAN + 1,
        ];
    }

    /**
     * @dataProvider closeByMarketCasesProvider
     */
    public function testCloseByMarket(Position $position, Ticker $ticker, BuyOrder $buyOrder, float $expectedCloseOrderVolume): void
    {
        $symbol = $position->symbol;
        $coin = $symbol->associatedCoin();
        $side = $position->side;

        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $position);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->haveAvailableSpotBalance($symbol, 0);
        $this->haveContractWalletBalanceAllUsedToOpenPosition($position);
        $this->expectsToMakeApiCalls(...self::cannotAffordBuyApiCallExpectations($symbol, [$buyOrder]));
        $this->expectsToMakeApiCalls(self::successCloseByMarketApiCallExpectation($symbol, $position->side, $expectedCloseOrderVolume));

        // Assert
        $expectedProfit = PnlHelper::getPnlInUsdt($position, $ticker->lastPrice, $expectedCloseOrderVolume);
        $transferToSpotAmount = $expectedProfit * self::TRANSFER_TO_SPOT_PROFIT_PART_WHEN_TAKE_PROFIT;
        $this->expectsInterTransferFromContractToSpot(new CoinAmount($coin, $transferToSpotAmount));

        // Act
        ($this->handler)(new PushBuyOrders($symbol, $side));

        // Assert
        $reopenOnPrice = $position->isShort() ? $ticker->indexPrice->sub(self::REOPEN_DISTANCE) : $ticker->indexPrice->add(self::REOPEN_DISTANCE);

        self::seeBuyOrdersInDb(
            clone $buyOrder,
            # also must create BuyOrder to reopen closed volume on further movement
            new BuyOrder($buyOrder->getId() + 1, $reopenOnPrice, $expectedCloseOrderVolume, $position->side, [BuyOrder::ONLY_IF_HAS_BALANCE_AVAILABLE_CONTEXT => true])
        );
    }

    public function closeByMarketCasesProvider(): iterable
    {
        $needBuyOrderVolume = 0.003;
        $position = PositionFactory::short(self::SYMBOL, 30000);
        $minProfitPercent = self::USE_PROFIT_AFTER_LAST_PRICE_PNL_PERCENT_IF_CANNOT_AFFORD_BUY;

        $lastPrice = PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, $minProfitPercent)->value() - 1;
        yield [
            'position' => $position,
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, $needBuyOrderVolume, $position->side),
            'expectedCloseOrderVolume' => self::getExpectedVolumeToClose($needBuyOrderVolume, Price::float($lastPrice)->getPnlPercentFor($position)),
        ];

        $needBuyOrderVolume = 0.004;
        $lastPrice = PnlHelper::targetPriceByPnlPercentFromPositionEntry($position, $minProfitPercent + 100)->value();
        yield [
            'position' => $position,
            'ticker' => $ticker = TickerFactory::create(self::SYMBOL, $lastPrice + 20, $lastPrice + 10, $lastPrice + 1),
            'buyOrder' => new BuyOrder(10, $ticker->indexPrice, $needBuyOrderVolume, $position->side),
            'expectedCloseOrderVolume' => self::getExpectedVolumeToClose($needBuyOrderVolume, Price::float($lastPrice)->getPnlPercentFor($position)),
        ];
    }

    private static function getExpectedVolumeToClose(float $needBuyVolume, float $lastPriceCurrentPnlPercent): float
    {
        return VolumeHelper::forceRoundUp($needBuyVolume / ($lastPriceCurrentPnlPercent * 0.5 / 100));
    }
}
