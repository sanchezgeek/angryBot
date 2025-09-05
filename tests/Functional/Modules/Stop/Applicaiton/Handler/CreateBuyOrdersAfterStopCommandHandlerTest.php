<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\Handler;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Buy\Application\Service\BaseStopLength\Processor\PredefinedStopLengthProcessor;
use App\Buy\Domain\ValueObject\StopStrategy\Strategy\PredefinedStopLength;
use App\Domain\BuyOrder\Enum\BuyOrderState;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\Order;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\PriceDistanceSelector as Distance;
use App\Domain\Trading\Enum\TimeFrame;
use App\Domain\Trading\Enum\TradingStyle;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\Contract\Command\CreateBuyOrderAfterStop;
use App\Stop\Application\Handler\CreateBuyOrderAfterStopCommandHandler;
use App\Stop\Application\Settings\CreateOppositeBuySettings;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\SymbolsDependentTester;
use App\Tests\Mixin\TA\TaToolsProviderMocker;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\Trading\TradingParametersMocker;
use App\Tests\Stub\TA\TradingParametersProviderStub;
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
    use TradingParametersMocker;
    use SettingsAwareTest;

    private const int DEFAULT_ATR_PERIOD = CreateBuyOrderAfterStopCommandHandler::DEFAULT_ATR_PERIOD;
    private const TimeFrame DEFAULT_TIMEFRAME_FOR_ATR = PredefinedStopLengthProcessor::DEFAULT_TIMEFRAME_FOR_ATR;
    private const int BIG_STOP_VOLUME_MULTIPLIER = CreateBuyOrderAfterStopCommandHandler::BIG_STOP_VOLUME_MULTIPLIER;

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
        float $wholePositionSize,
        array $expectedBuyOrders,
        bool $martingaleEnabled = false,
        array $ordersDoublesHashes = [],
    ): void {
        $symbol = $ticker->symbol;
        // @todo get from stop
        self::setTradingParametersStubInContainer(self::getTradingParametersStub($symbol));

        $this->overrideSetting(SettingAccessor::exact(CreateOppositeBuySettings::Martingale_Enabled, $stop->getSymbol(), $stop->getPositionSide()), $martingaleEnabled);

        $this->haveTicker($ticker);
        $this->applyDbFixtures(new StopFixture($stop));

        $this->runMessageConsume(new CreateBuyOrderAfterStop($stop->getId(), $wholePositionSize, $ticker->markPrice->value(), $ordersDoublesHashes));

        $this->seeBuyOrdersInDb(...$expectedBuyOrders);
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        ### BTCUSDT SHORT
        $ticker = TickerFactory::create($symbol, 100100);

        $stop = StopBuilder::short(10, 100000, 0.001)->build()->setExchangeOrderId('123456')->setIsWithoutOppositeOrder();
        $wholePositionSize = 0.006;
        yield self::caseDescription($stop, $wholePositionSize, []) => [
            $ticker,
            $stop,
            $wholePositionSize,
            [],
        ];

        $stop = StopBuilder::short(10, 100000, 0.001)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.006;
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::short(10, 100000, 0.001)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.01;
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::short(10, 100000, 0.02)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.1;
        $doublesHashes = ['123456', '1234567', '1234568'];
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize, martingaleEnabled: true, doublesHashes: $doublesHashes);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
            true,
            $doublesHashes,
        ];

        $stop = StopBuilder::short(10, 100000, 0.004)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.01;
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::short(10, 100000, 0.01)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.1;
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
        ];

        ### BTCUSDT LONG
        $ticker = TickerFactory::create($symbol, 99000);

        $stop = StopBuilder::long(10, 100000, 0.001)->build()->setExchangeOrderId('123456')->setIsWithoutOppositeOrder();
        $wholePositionSize = 0.006;
        yield self::caseDescription($stop, $wholePositionSize, []) => [
            $ticker,
            $stop,
            $wholePositionSize,
            [],
        ];

        $stop = StopBuilder::long(10, 100000, 0.001)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.006;
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::long(10, 100000, 0.002)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.01;
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
        ];

        $stop = StopBuilder::long(10, 100000, 0.01)->build()->setExchangeOrderId('123456');
        $wholePositionSize = 0.1;
        $expectedBuyOrders = self::expectedBuyOrders($stop, $wholePositionSize);
        yield self::caseDescription($stop, $wholePositionSize, $expectedBuyOrders) => [
            $ticker,
            $stop,
            $wholePositionSize,
            $expectedBuyOrders,
        ];
    }

    /**
     * @param Stop $stop
     * @return BuyOrder[]
     */
    private static function expectedBuyOrders(
        Stop $stop,
        float $wholePositionSize,
        int $fromId = 1,
        $martingaleEnabled = false,
        ?array $doublesHashes = null,
    ): array {
        if (!$stop->getExchangeOrderId()) {
            throw new RuntimeException('exchangeOrderId must be set');
        }

        $symbol = $stop->getSymbol();
        $side = $stop->getPositionSide();
        $stopPrice = $symbol->makePrice($stop->getPrice());
        $stopVolume = $stop->getVolume();

        $distanceOverride = $stop->getOppositeOrderDistance();

        $isMinVolume = $stopVolume <= $symbol->minOrderQty();
        $isBigStop = FloatHelper::round($stopVolume / $wholePositionSize) >= 0.1;

        $martingaleOrders = [];
        $orders = [];
        if ($isMinVolume || !$isBigStop || $distanceOverride) {
            if ($distanceOverride instanceof Percent) {
                $distanceOverride = PnlHelper::convertPnlPercentOnPriceToAbsDelta($distanceOverride, $stopPrice);
            }

            $distance = $distanceOverride ?? self::getOppositeOrderDistance($stop, PriceDistanceSelector::Standard);

            $orders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume, $distance, [BuyOrder::FORCE_BUY_CONTEXT => !$isBigStop]);
        } else {
            $withForceBuy = [BuyOrder::FORCE_BUY_CONTEXT => true];

            $doubleHashes = $doublesHashes ?? [];
            if ($martingaleEnabled) {
                OutputHelper::print(sprintf('martingale enabled for %s %s', $symbol->name(), $side->value));

                $tradingStyle = TradingStyle::Conservative;

                $martingaleOrdersStopLength = match ($tradingStyle) {
                    TradingStyle::Aggressive => Distance::Standard,
                    TradingStyle::Conservative => Distance::ModerateShort,
                    TradingStyle::Cautious => Distance::Short,
                };
                $forcedWithShortStop = BuyOrder::addStopCreationStrategyToContext($withForceBuy, new PredefinedStopLength($martingaleOrdersStopLength));

                if (!$doubleHashes) {
                    $doubleHashes[] = md5(uniqid('BO_double', true));
                    $doubleHashes[] = md5(uniqid('BO_double', true));
                    $doubleHashes[] = md5(uniqid('BO_double', true));
                }

                $martingaleOrders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 3, PriceDistanceSelector::Standard, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);
                $martingaleOrders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 3, PriceDistanceSelector::ModerateLong, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);
                $martingaleOrders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 3, PriceDistanceSelector::Long, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);
                $martingaleOrders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 2, PriceDistanceSelector::VeryLong, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]]), sign: -1);

                $martingaleOrders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 2, PriceDistanceSelector::ModerateShort, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[1]]), sign: -1);
                $martingaleOrders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 3, PriceDistanceSelector::Short, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[0]]), sign: -1);
                $martingaleOrders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 3, PriceDistanceSelector::VeryShort, array_merge($forcedWithShortStop, [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[0]]), sign: -1);
            }

            $orders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 5, PriceDistanceSelector::VeryShort, array_merge($withForceBuy, $doubleHashes ? [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[0]] : []));
            $orders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 3, PriceDistanceSelector::Short, $doubleHashes ? [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[1]] : []);
            $orders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 5, PriceDistanceSelector::ModerateShort, array_merge($withForceBuy, $doubleHashes ? [BuyOrder::DOUBLE_HASH_FLAG => $doubleHashes[2]] : []));
            $orders[] = self::orderBasedOnLengthEnum($stop, $stopPrice, $stopVolume / 5, PriceDistanceSelector::Standard);
        }

        $orders = new OrdersLimitedWithMaxVolume(
            new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(...$orders)),
            $stopVolume
        );

        $buyOrders = [];

        // active
        foreach ($orders->getOrders() as $order) {
            $buyOrders[] = new BuyOrder($fromId, $order->price(), $order->volume(), $symbol, $side, $order->context(), BuyOrderState::Active);
            $fromId++;
        }

        foreach ($martingaleOrders as $order) {
            $buyOrders[] = new BuyOrder($fromId, $order->price(), $order->volume(), $symbol, $side, $order->context());
            $fromId++;
        }

        return $buyOrders;
    }

    private static function orderBasedOnLengthEnum(Stop $stop, SymbolPrice $refPrice, float $volume, PriceDistanceSelector|float $length, array $additionalContext = [], int $sign = 1): Order
    {
        $commonContext = [
            BuyOrder::IS_OPPOSITE_AFTER_SL_CONTEXT => true,
            BuyOrder::ONLY_AFTER_EXCHANGE_ORDER_EXECUTED_CONTEXT => $stop->getExchangeOrderId(),
            BuyOrder::OPPOSITE_SL_ID_CONTEXT => $stop->getId(),
        ];

        $side = $stop->getPositionSide();

        $distance = $length instanceof PriceDistanceSelector ? self::getOppositeOrderDistance($stop, $length) : $length;
        $distance*= $sign;

        $price = $side->isShort() ? $refPrice->sub($distance) : $refPrice->add($distance);
        $volume = $stop->getSymbol()->roundVolume($volume);
        $context = array_merge($commonContext, $additionalContext);

        return new Order($price, $volume, $context);
    }

    private static function getOppositeOrderDistance(Stop $stop, PriceDistanceSelector $lengthSelector): float
    {
        $stopPrice = $stop->getPrice();

        return PnlHelper::convertPnlPercentOnPriceToAbsDelta(
            PnlHelper::transformPriceChangeToPnlPercent(
                self::getTradingParametersStub($stop->getSymbol())->transformLengthToPricePercent($stop->getSymbol(), $lengthSelector, self::DEFAULT_TIMEFRAME_FOR_ATR, self::DEFAULT_ATR_PERIOD)
            ),
            $stopPrice
        );
    }

    /**
     * @param BuyOrder[] $createdBoyOrders
     */
    private static function caseDescription(Stop $stop, float $wholePositionSize, array $createdBoyOrders): string
    {
        $boDef = [];
        foreach ($createdBoyOrders as $boyOrder) {
            $boDef[] = sprintf('price=%s, volume=%s', $boyOrder->getPrice(), $boyOrder->getVolume());
        }
        $boDef = implode(' | ', $boDef);

        return sprintf(
            '[%s %s] stop[price=%s, volume=%s] wholePositionSize=%s => %s',
            $stop->getSymbol()->name(),
            $stop->getPositionSide()->title(),
            $stop->getPrice(),
            $stop->getVolume(),
            $wholePositionSize,
            $boDef,
        );
    }

    private static function getTradingParametersStub(SymbolInterface $symbol): TradingParametersProviderStub
    {
        $timeframe = self::DEFAULT_TIMEFRAME_FOR_ATR;
        $period = self::DEFAULT_ATR_PERIOD;

        return
            new TradingParametersProviderStub()
                ->addTransformedLengthResult(Percent::string('0.3%'), $symbol, PriceDistanceSelector::VeryVeryShort, $timeframe, $period)
                ->addTransformedLengthResult(Percent::string('0.5%'), $symbol, PriceDistanceSelector::VeryShort, $timeframe, $period)
                ->addTransformedLengthResult(Percent::string('0.6%'), $symbol, PriceDistanceSelector::Short, $timeframe, $period)
                ->addTransformedLengthResult(Percent::string('0.7%'), $symbol, PriceDistanceSelector::ModerateShort, $timeframe, $period)
                ->addTransformedLengthResult(Percent::string('1%'), $symbol, PriceDistanceSelector::Standard, $timeframe, $period)
                ->addTransformedLengthResult(Percent::string('1.5%'), $symbol, PriceDistanceSelector::ModerateLong, $timeframe, $period)
                ->addTransformedLengthResult(Percent::string('2%'), $symbol, PriceDistanceSelector::Long, $timeframe, $period)
                ->addTransformedLengthResult(Percent::string('10%'), $symbol, PriceDistanceSelector::VeryLong, $timeframe, $period)
            ;
    }
}
