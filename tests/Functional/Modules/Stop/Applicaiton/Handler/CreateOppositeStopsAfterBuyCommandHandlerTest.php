<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\Handler;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Buy\Application\Command\CreateStopsAfterBuy;
use App\Buy\Application\Handler\Command\CreateStopsAfterBuyCommandHandler;
use App\Buy\Application\Service\BaseStopLength\Processor\PredefinedStopLengthProcessor;
use App\Buy\Application\StopPlacementStrategy;
use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Domain\Value\Percent\Percent;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Helper\Buy\BuyOrderTestHelper;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\SymbolsDependentTester;
use App\Tests\Mixin\TA\TaToolsProviderMocker;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group stop
 * @group oppositeOrders
 *
 * @covers \App\Buy\Application\Handler\Command\CreateStopsAfterBuyCommandHandler
 */
final class CreateOppositeStopsAfterBuyCommandHandlerTest extends KernelTestCase
{
    use MessageConsumerTrait;
    use ByBitV5ApiRequestsMocker;
    use StopsTester;
    use BuyOrdersTester;
    use SymbolsDependentTester;
    use TaToolsProviderMocker;

    private const int CALC_BASE_STOP_LENGTH_DEFAULT_INTERVALS_COUNT = PredefinedStopLengthProcessor::DEFAULT_INTERVALS_COUNT;
    private const CandleIntervalEnum CALC_BASE_STOP_LENGTH_DEFAULT_INTERVAL = PredefinedStopLengthProcessor::DEFAULT_INTERVAL;

    private const int CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT = CreateStopsAfterBuyCommandHandler::CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT;
    private const CandleIntervalEnum CHOOSE_FINAL_STOP_STRATEGY_INTERVAL = CreateStopsAfterBuyCommandHandler::CHOOSE_FINAL_STOP_STRATEGY_INTERVAL;

    protected function setUp(): void
    {
        parent::setUp();

        self::getContainer()->set(TAToolsProviderInterface::class, $this->initializeTaProviderStub());
    }

    /**
     * @dataProvider cases
     */
    public function testCreateStopsAfterBuy(
        Ticker $ticker,
        Position $position,
        BuyOrder $buyOrder,
        Percent $atrForStopLength,
        Percent $strForStopPlacementStrategy,
        Stop $expectedStop
    ): void {
        $symbol = $ticker->symbol;

        $this->haveTicker($ticker);
        $this->havePosition($position->symbol, $position);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->analysisToolsProviderStub
            ->mockedTaTools($symbol, self::CHOOSE_FINAL_STOP_STRATEGY_INTERVAL)
            ->addAtrResult(
                period: self::CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT,
                percentChange: $strForStopPlacementStrategy,
                refPrice: $ticker->indexPrice->value()
            );

        $this->analysisToolsProviderStub
            ->mockedTaTools($symbol, self::CALC_BASE_STOP_LENGTH_DEFAULT_INTERVAL)
            ->addAtrResult(
                period: self::CALC_BASE_STOP_LENGTH_DEFAULT_INTERVALS_COUNT,
                percentChange: $atrForStopLength,
                refPrice: $ticker->indexPrice->value(),
            );

        $this->runMessageConsume(new CreateStopsAfterBuy($buyOrder->getId()));

        $this->seeStopsInDb($expectedStop);
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $ticker = TickerFactory::create($symbol, 100100);
        $position = PositionFactory::short($symbol, 100000);
        $averagePriceMoveToSelectStopPriceStrategy = Percent::string('1.5%');
        $averagePriceMoveToCalcStopLength = Percent::string('3%');

        yield 'create BTCUSDT SHORT Stop' => [
            $ticker,
            $position,
            $buyOrder = BuyOrderTestHelper::setActive(BuyOrderBuilder::short(10, 100500, 0.01)->build()),
            $averagePriceMoveToCalcStopLength,
            $averagePriceMoveToSelectStopPriceStrategy,
            StopBuilder::short(1, 101505.0, $buyOrder->getVolume())->build(),
        ];
    }

    private static function expectedStopPrice(BuyOrder $buyOrder): float
    {
        $stopDistance = StopPlacementStrategy::getDefaultStrategyStopOrderDistance($buyOrder->getVolume());

        return $buyOrder->getPrice() + ($buyOrder->getPositionSide()->isShort() ? $stopDistance : -$stopDistance);
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('cases: short_stop, ...');
    }
}
