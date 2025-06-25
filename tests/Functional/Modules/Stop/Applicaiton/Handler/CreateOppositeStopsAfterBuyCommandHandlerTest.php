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
use App\TechnicalAnalysis\Application\Contract\Query\CalcAverageTrueRange;
use App\TechnicalAnalysis\Application\Contract\Query\FindAveragePriceChange;
use App\TechnicalAnalysis\Application\Contract\TAToolsProviderInterface;
use App\TechnicalAnalysis\Application\Service\TechnicalAnalysisTools;
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
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Stub\CalcAverageTrueRangeHandlerStub;
use App\Tests\Stub\FindAveragePriceChangeHandlerStub;
use App\Tests\Stub\TAToolsProviderStub;
use PHPUnit\Framework\MockObject\MockObject;
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

    private const int CALC_BASE_STOP_LENGTH_DEFAULT_INTERVALS_COUNT = PredefinedStopLengthProcessor::DEFAULT_INTERVALS_COUNT;
    private const CandleIntervalEnum CALC_BASE_STOP_LENGTH_DEFAULT_INTERVAL = PredefinedStopLengthProcessor::DEFAULT_INTERVAL;

    private const int CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT = CreateStopsAfterBuyCommandHandler::CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT;
    private const CandleIntervalEnum CHOOSE_FINAL_STOP_STRATEGY_INTERVAL = CreateStopsAfterBuyCommandHandler::CHOOSE_FINAL_STOP_STRATEGY_INTERVAL;

    private MockObject|TAToolsProviderInterface $analysisToolsFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analysisToolsFactory = new TAToolsProviderStub();

        self::getContainer()->set(TAToolsProviderInterface::class, $this->analysisToolsFactory);
    }

    /**
     * @dataProvider cases
     */
    public function testCreateStopsAfterBuy(
        Ticker $ticker,
        Position $position,
        BuyOrder $buyOrder,
        Percent $averagePriceMoveToCalcStopLength,
        Percent $averagePriceMoveToSelectStopPriceStrategy,
        Stop $expectedStop
    ): void {
        $this->haveTicker($ticker);
        $this->havePosition($position->symbol, $position);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->analysisToolsFactory->addItem($ticker->symbol, self::CHOOSE_FINAL_STOP_STRATEGY_INTERVAL, new TechnicalAnalysisTools(
            $ticker->symbol,
            new FindAveragePriceChangeHandlerStub(),
            $this->calcAtrHandlerStub($ticker, [
                'entry' => new CalcAverageTrueRange($ticker->symbol, self::CHOOSE_FINAL_STOP_STRATEGY_INTERVAL, self::CHOOSE_FINAL_STOP_STRATEGY_INTERVALS_COUNT),
                'percentResult' => $averagePriceMoveToSelectStopPriceStrategy,
            ], [
                'entry' => new CalcAverageTrueRange($ticker->symbol, self::CALC_BASE_STOP_LENGTH_DEFAULT_INTERVAL, self::CALC_BASE_STOP_LENGTH_DEFAULT_INTERVALS_COUNT),
                'percentResult' => $averagePriceMoveToCalcStopLength,
            ])
        ));

        $this->runMessageConsume(new CreateStopsAfterBuy($buyOrder->getId()));

        $this->seeStopsInDb($expectedStop);
    }

    /**
     * @param Ticker $ticker
     * @param array<array{entry: FindAveragePriceChange, percentResult: Percent, absoluteResult: ?float}> $cases
     * @return FindAveragePriceChangeHandlerStub
     */
    private function averagePriceChangeHandlerStub(
        Ticker $ticker,
        array ...$cases
    ): FindAveragePriceChangeHandlerStub {
        $averagePriceChangeHandler = new FindAveragePriceChangeHandlerStub();

        foreach ($cases as $case) {
            $entry = $case['entry'];
            $percentResult = $case['percentResult'];

            $averagePriceChangeHandler->addItem(
                $entry,
                $percentResult,
                $case['absoluteResult'] ?? $percentResult->of($ticker->indexPrice->value())
            );
        }

        return $averagePriceChangeHandler;
    }

    /**
     * @param Ticker $ticker
     * @param array<array{entry: CalcAverageTrueRange, percentResult: Percent, atrAbsolute: ?float}> $cases
     * @return CalcAverageTrueRangeHandlerStub
     */
    private function calcAtrHandlerStub(
        Ticker $ticker,
        array ...$cases
    ): CalcAverageTrueRangeHandlerStub {
        $calcAverageTrueRangeHandler = new CalcAverageTrueRangeHandlerStub();

        foreach ($cases as $case) {
            $entry = $case['entry'];
            $percentResult = $case['percentResult'];

            $calcAverageTrueRangeHandler->addItem(
                $entry,
                $case['atrAbsolute'] ?? $percentResult->of($ticker->indexPrice->value()),
                $percentResult,
            );
        }

        return $calcAverageTrueRangeHandler;
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
