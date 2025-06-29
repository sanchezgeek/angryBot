<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Buy\Application\Handler;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Buy\Application\Service\BaseStopLength\Processor\PredefinedStopLengthProcessor;
use App\Buy\Application\StopPlacementStrategy;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Order;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Value\Percent\Percent;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use App\Stop\Application\Handler\CreateBuyOrderAfterStopCommandHandler;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\SymbolsDependentTester;
use App\Tests\Mixin\TA\TaToolsProviderMocker;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Stub\TA\TradingParametersProviderStub;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group stop
 * @group oppositeOrders
 *
 * @covers \App\Stop\Application\Handler\CreateBuyOrderAfterStopCommandHandler
 */
final class CreateBuyOrdersAfterStopCommandHandlerTest extends KernelTestCase
{
    use MessageConsumerTrait;
    use ByBitV5ApiRequestsMocker;
    use StopsTester;
    use BuyOrdersTester;
    use SymbolsDependentTester;
    use TaToolsProviderMocker;

    private static TradingParametersProviderStub $tradingParametersProviderStub;

    private const int DEFAULT_ATR_PERIOD = CreateBuyOrderAfterStopCommandHandler::DEFAULT_ATR_PERIOD;
    private const TimeFrame DEFAULT_TIMEFRAME_FOR_ATR = PredefinedStopLengthProcessor::DEFAULT_TIMEFRAME_FOR_ATR;
    private const int BIG_STOP_VOLUME_MULTIPLIER = CreateBuyOrderAfterStopCommandHandler::BIG_STOP_VOLUME_MULTIPLIER;

    private static function getTradingParametersStub(SymbolInterface $symbol): TradingParametersProviderStub
    {
        $timeframe = self::DEFAULT_TIMEFRAME_FOR_ATR;
        $period = self::DEFAULT_ATR_PERIOD;

        return
            new TradingParametersProviderStub()
                ->addRegularOppositeBuyOrderLengthResults($symbol, PredefinedStopLengthSelector::Standard, $timeframe, $period, Percent::string('1%'))
                ->addRegularOppositeBuyOrderLengthResults($symbol, PredefinedStopLengthSelector::ModerateLong, $timeframe, $period, Percent::string('1.5%'))
                ->addRegularOppositeBuyOrderLengthResults($symbol, PredefinedStopLengthSelector::Long, $timeframe, $period, Percent::string('2%'))
        ;
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('cases: specified distance, ...');
    }

    /**
     * @dataProvider cases
     *
     * @param BuyOrder[] $expectedBuyOrders
     */
    public function testCreateBuyOrdersAfterStop(
        Ticker $ticker,
        Stop $stop,
        array $expectedBuyOrders
    ): void {
        $symbol = $ticker->symbol;
        // @todo get from stop
        self::getContainer()->set(TradingParametersProviderInterface::class, self::getTradingParametersStub($symbol));

        $this->haveTicker($ticker);
        $this->applyDbFixtures(new StopFixture($stop));

        $this->runMessageConsume(new CreateBuyOrderAfterStop($stop->getId()));

        $this->seeBuyOrdersInDb(...$expectedBuyOrders);
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        ### BTCUSDT SHORT
        $ticker = TickerFactory::create($symbol, 100100);

        $stop = StopBuilder::short(10, 100000, 0.001)->build()->setExchangeOrderId('123456')->setIsWithoutOppositeOrder();
        yield self::caseDescription($stop, []) => [
            $ticker,
            $stop,
            [],
        ];

        $stop = StopBuilder::short(10, 100000, 0.001)->build()->setExchangeOrderId('123456');
        $expectedBuyOrders = self::expectedBuyOrders($stop);
        yield self::caseDescription($stop, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::short(10, 100000, 0.01)->build()->setExchangeOrderId('123456');
        $expectedBuyOrders = self::expectedBuyOrders($stop);
        yield self::caseDescription($stop, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $expectedBuyOrders,
        ];

        ### BTCUSDT LONG
        $ticker = TickerFactory::create($symbol, 99000);

        $stop = StopBuilder::long(10, 100000, 0.001)->build()->setExchangeOrderId('123456');
        $expectedBuyOrders = self::expectedBuyOrders($stop);
        yield self::caseDescription($stop, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::long(10, 100000, 0.01)->build()->setExchangeOrderId('123456');
        $expectedBuyOrders = self::expectedBuyOrders($stop);
        yield self::caseDescription($stop, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $expectedBuyOrders,
        ];

        ### AAVEUSDT LONG
        $symbol = SymbolEnum::AAVEUSDT;
        $ticker = TickerFactory::create($symbol, 391.2);

        $stop = StopBuilder::short(10, 391.22, 0.02, $symbol)->build()->setExchangeOrderId('123456');
        $expectedBuyOrders = self::expectedBuyOrders($stop);
        yield self::caseDescription($stop, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::short(10, 391.22, 0.5, $symbol)->build()->setExchangeOrderId('123456');
        $expectedBuyOrders = self::expectedBuyOrders($stop);
        yield self::caseDescription($stop, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $expectedBuyOrders,
        ];
    }

    /**
     * @param Stop $stop
     * @return BuyOrder[]
     */
    private static function expectedBuyOrders(
        Stop $stop,
        int $fromId = 1,
    ): array {
        if (!$stop->getExchangeOrderId()) {
            throw new RuntimeException('exchangeOrderId must be set');
        }
        $symbol = $stop->getSymbol();
        $side = $stop->getPositionSide();
        $stopPrice = $symbol->makePrice($stop->getPrice());
        $stopVolume = $stop->getVolume();


        $distanceOverride = $stop->getOppositeOrderDistance();
        if ($distanceOverride !== null) {
            $baseDistance = $distanceOverride;
        } else {
            $baseDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::oppositeBuyOrderPnlDistance($stop, PredefinedStopLengthSelector::Standard), $stopPrice);
        }

        $baseBuyOrderPrice = $side->isShort() ? $stopPrice->sub($baseDistance) : $stopPrice->add($baseDistance);

        $minOrderQty = ExchangeOrder::roundedToMin($symbol, $symbol->minOrderQty(), $baseBuyOrderPrice)->getVolume();
        $bigStopVolume = $symbol->roundVolume($minOrderQty * self::BIG_STOP_VOLUME_MULTIPLIER);

        if ($stopVolume >= $bigStopVolume) {
            $volumeGrid = [
                $symbol->roundVolume($stopVolume / 3),
                $symbol->roundVolume($stopVolume / 4.5),
                $symbol->roundVolume($stopVolume / 3.5),
            ];

            if ($distanceOverride) {
                $priceGrid = [
                    $baseBuyOrderPrice,
                    $side->isShort() ? $baseBuyOrderPrice->sub($baseDistance / 3.8) : $baseBuyOrderPrice->add($baseDistance / 3.8),
                    $side->isShort() ? $baseBuyOrderPrice->sub($baseDistance / 2)   : $baseBuyOrderPrice->add($baseDistance / 2),
                ];
            } else {
                $moderateLongLengthDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::oppositeBuyOrderPnlDistance($stop, PredefinedStopLengthSelector::ModerateLong), $stopPrice);
                $longLengthDistance = PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::oppositeBuyOrderPnlDistance($stop, PredefinedStopLengthSelector::Long), $stopPrice);

                $priceGrid = [
                    $baseBuyOrderPrice,
                    $side->isShort() ? $stopPrice->sub($moderateLongLengthDistance) : $stopPrice->add($moderateLongLengthDistance),
                    $side->isShort() ? $stopPrice->sub($longLengthDistance) : $stopPrice->add($longLengthDistance),
                ];
            }

            $orders = [];
            foreach ($priceGrid as $key => $price) {
                $orders[] = new Order($price, $volumeGrid[$key]);
            }
        } else {
            $orders = [
                new Order($baseBuyOrderPrice, $symbol->roundVolume($stopVolume))
            ];
        }

        $orders = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)),
            $stopVolume
        );

        $buyOrders = [];
        foreach ($orders as $order) {
            $buyOrders[] = new BuyOrder($fromId, $order->price(), $order->volume(), $symbol, $side);
            $fromId++;
        }

        foreach ($buyOrders as $buyOrder) {
            $buyOrder->setOnlyAfterExchangeOrderExecutedContext($stop->getExchangeOrderId());
            $buyOrder->setOppositeStopId($stop->getId());
            $buyOrder->setIsOppositeBuyOrderAfterStopLossContext();
            // @todo | oppositeBuyOrder | only if source BuyOrder in chain was with force // $order->setIsForceBuyOrderContext();
            $buyOrder->setOppositeOrdersDistance($baseDistance * CreateBuyOrderAfterStopCommandHandler::OPPOSITE_SL_PRICE_MODIFIER);
        }

        return $buyOrders;
    }

    private static function oppositeBuyOrderPnlDistance(Stop $stop, PredefinedStopLengthSelector $lengthSelector): Percent
    {
        return PnlHelper::transformPriceChangeToPnlPercent(
            self::getTradingParametersStub($stop->getSymbol())->regularOppositeBuyOrderLength($stop->getSymbol(), $lengthSelector, self::DEFAULT_TIMEFRAME_FOR_ATR, self::DEFAULT_ATR_PERIOD)
        );
    }

    /**
     * @param BuyOrder[] $createdBoyOrders
     */
    private static function caseDescription(Stop $stop, array $createdBoyOrders): string
    {
        $boDef = [];
        foreach ($createdBoyOrders as $boyOrder) {
            $boDef[] = sprintf('price=%s, volume=%s', $boyOrder->getPrice(), $boyOrder->getVolume());
        }
        $boDef = implode(' | ', $boDef);

        return sprintf(
            '[%s %s] stop[price=%s, volume=%s] => %s',
            $stop->getSymbol()->name(),
            $stop->getPositionSide()->title(),
            $stop->getPrice(),
            $stop->getVolume(),
            $boDef
        );
    }
}
