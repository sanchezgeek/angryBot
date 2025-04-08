<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationHandler;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactoryInterface;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersInterface;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\PriceRange;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\Logger\AppErrorsLoggerTrait;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Worker\AppContext;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group liquidation
 *
 * @covers CheckPositionIsUnderLiquidationHandler
 */
class RemoveStaleStopsTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;
    use ByBitV5ApiRequestsMocker;
    use AppErrorsLoggerTrait;

    private LiquidationDynamicParametersInterface|MockObject $liquidationDynamicParameters;
    private CheckPositionIsUnderLiquidationHandler $handler;

    protected function setUp(): void
    {
        self::truncateStops();

        $liquidationDynamicParametersFactory = $this->createMock(LiquidationDynamicParametersFactoryInterface::class);

        $this->liquidationDynamicParameters = $this->createMock(LiquidationDynamicParametersInterface::class);
        $liquidationDynamicParametersFactory->method('create')->willReturn($this->liquidationDynamicParameters);

        $this->handler = new CheckPositionIsUnderLiquidationHandler(
            self::getContainer()->get(ExchangeServiceInterface::class),
            self::getContainer()->get(PositionServiceInterface::class),
            self::getContainer()->get(ExchangeAccountServiceInterface::class),
            self::getContainer()->get(OrderServiceInterface::class),
            self::getContainer()->get(StopServiceInterface::class),
            self::getContainer()->get(StopRepositoryInterface::class),
            self::getTestAppErrorsLogger(),
            null,
            $liquidationDynamicParametersFactory
        );

        $this->handler->setOnlyRemoveStale(true);
    }

    /**
     * @dataProvider removeStaleStopsTestCases
     */
    public function testRemoveStaleStops(
        CheckPositionIsUnderLiquidation $message,
        Position $position,
        Ticker $ticker,
        array $existentStops,
        PriceRange $actualStopsRange,
        PriceRange $criticalPriceRange,
        array $stopsExpectedAfterHandle,
        ?string $note = null,
        bool $debug = false
    ): void {
        AppContext::setIsDebug($debug);

        $this->haveTicker($ticker);
        $this->havePosition($ticker->symbol, $position);
        $this->haveStopsInDb(...$existentStops);
        $this->haveActiveConditionalStopsOnMultipleSymbols();

        $this->liquidationDynamicParameters->method('actualStopsRange')->willReturn($actualStopsRange);
        $this->liquidationDynamicParameters->method('criticalRange')->willReturn($criticalPriceRange);

        // Act
        ($this->handler)($message);

        // Arrange
        self::seeStopsInDb(...$stopsExpectedAfterHandle);
    }

    public static function removeStaleStopsTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $message = new CheckPositionIsUnderLiquidation(symbol: $symbol);

        # long
        $criticalPriceRange = PriceRange::create(29000, 30150);
        $actualStopsPriceRange = PriceRange::create(30178.99, 30284.67);
        $long = PositionBuilder::long()->entry(30000)->size(1)->liq(29999)->build();
        $ticker = TickerFactory::withEqualPrices($symbol, 100500);

        yield [
            $message,
            $long,
            $ticker,
            [
                (new Stop(10, 30290, 0.1, null, $symbol, $long->side)),
                (new Stop(20, 30290, 0.1, null, $symbol, $long->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(30, 30250, 0.1, null, $symbol, $long->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(40, 30170, 0.1, null, $symbol, $long->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(50, 30170, 0.1, null, $symbol, $long->side)),
                (new Stop(60, 30134, 0.1, null, $symbol, $long->side))->setIsAdditionalStopFromLiquidationHandler(),
            ],
            $actualStopsPriceRange,
            $criticalPriceRange,
            [
                (new Stop(10, 30290, 0.1, null, $symbol, $long->side)),
                (new Stop(30, 30250, 0.1, null, $symbol, $long->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(50, 30170, 0.1, null, $symbol, $long->side)),
                (new Stop(60, 30134, 0.1, null, $symbol, $long->side))->setIsAdditionalStopFromLiquidationHandler(),
            ],
        ];

        # short
        $criticalPriceRange = PriceRange::create(30001, 29800);
        $actualStopsPriceRange = PriceRange::create(29700, 29600);
        $short = PositionBuilder::short()->entry(30000)->size(1)->liq(30001)->build();
        $ticker = TickerFactory::withEqualPrices($symbol, 100500);

        yield [
            $message,
            $short,
            $ticker,
            [
                (new Stop(10, 29500, 0.1, null, $symbol, $short->side)),
                (new Stop(20, 29500, 0.1, null, $symbol, $short->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(30, 29650, 0.1, null, $symbol, $short->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(40, 29750, 0.1, null, $symbol, $short->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(50, 29750, 0.1, null, $symbol, $short->side)),
                (new Stop(60, 29850, 0.1, null, $symbol, $short->side))->setIsAdditionalStopFromLiquidationHandler(),
            ],
            $actualStopsPriceRange,
            $criticalPriceRange,
            [
                (new Stop(10, 29500, 0.1, null, $symbol, $short->side)),
                (new Stop(30, 29650, 0.1, null, $symbol, $short->side))->setIsAdditionalStopFromLiquidationHandler(),
                (new Stop(50, 29750, 0.1, null, $symbol, $short->side)),
                (new Stop(60, 29850, 0.1, null, $symbol, $short->side))->setIsAdditionalStopFromLiquidationHandler(),
            ],
        ];
    }
}
