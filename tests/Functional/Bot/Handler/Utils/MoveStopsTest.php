<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\Utils;

use App\Bot\Application\Messenger\Job\Utils\MoveStops;
use App\Bot\Application\Messenger\Job\Utils\MoveStopsHandler;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Tests\Stub\Bot\PositionServiceStub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;

/**
 * @covers \App\Bot\Application\Messenger\Job\Utils\MoveStopsHandler
 */
final class MoveStopsTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use StopsTester;

    private const SYMBOL = Symbol::BTCUSDT;

    protected PositionServiceInterface $positionServiceStub;
    protected ExchangeServiceInterface $exchangeServiceMock;

    private MoveStopsHandler $handler;

    public static function setUpBeforeClass(): void
    {
        self::truncateStops();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceStub = new PositionServiceStub();

        $this->handler = new MoveStopsHandler(self::getStopRepository(), $this->exchangeServiceMock, $this->positionServiceStub);

        self::ensureTableIsEmpty(Stop::class);
    }

    /**
     * @dataProvider shortStopsDataProvider
     *
     * @param Stop[] $stopsExpectedAfterHandle
     */
    public function testMoveSHORTStopsWhenPositionMoved(
        float $initialPositionEntryPrice,
        float $newPositionEntryPrice,
        array $initialStops,
        array $stopsExpectedAfterHandle,
    ): void {
        // Arrange
        $this->haveTicker(TickerFactory::create(self::SYMBOL, 29050));
        $this->positionServiceStub->havePosition(
            $position = PositionFactory::short(self::SYMBOL, $initialPositionEntryPrice)
        );
        $this->applyDbFixtures(...array_map(static fn (Stop $s) => new StopFixture($s), $initialStops));

        # first run (nothing's changed)
        ($this->handler)(new MoveStops($position->side));
        self::seeStopsInDb(...$initialStops);

        # position moved
        $this->positionServiceStub->havePosition(PositionFactory::short(self::SYMBOL, $newPositionEntryPrice), true);

        // Act I
        ($this->handler)(new MoveStops($position->side));

        // Assert I (stops moved)
        self::seeStopsInDb(...$stopsExpectedAfterHandle);
        $currentStops = self::getCurrentStopsSnapshot();

        // Act II
        ($this->handler)(new MoveStops($position->side));

        // Assert II (nothing's gonna changed)
        self::seeStopsInDb(...$currentStops);
    }

    public function shortStopsDataProvider(): iterable
    {
        $initialPositionEntryPrice = 29000;
        $newPositionEntryPrice = 28991; // `delta === 9.0` => `relevant $stops must be moved with 8.4`

        $priceStep = MoveStopsHandler::PRICE_STEP;
        $moveStep = MoveStopsHandler::MOVE_STEP * ($initialPositionEntryPrice - $newPositionEntryPrice) / $priceStep;

        yield '[under position] => any volume MUST be moved' => [
            '$initialPositionEntryPrice' => $initialPositionEntryPrice,
            '$newPositionEntryPrice' => $newPositionEntryPrice,
            '$initialStops' => $initialStops = [
                StopBuilder::short(10, 29060, 0.1)->withTD(10)->build(),
                StopBuilder::short(20, 29155, 0.2)->withTD(100)->build(),
                StopBuilder::short(30, 29055, 0.3)->withTD(5)->build(),
                StopBuilder::short(40, 29055, 0.003)->withTD(5)->build(),
                StopBuilder::short(50, 29055, 0.03)->withTD(5)->build(),
            ],
            '$stopsExpectedAfterHandle' => $this->modifyStops(static fn(Stop $s) => $s->setPrice($s->getPrice() - $moveStep)->clearOriginalPrice(), ...$initialStops),
        ];

        yield '[before position] && size >= 0.025 => MUST NOT be moved IN ANY RANGE' => [
            '$initialPositionEntryPrice' => $initialPositionEntryPrice,
            '$newPositionEntryPrice' => $newPositionEntryPrice,
            '$initialStops' => $initialStops = [
                StopBuilder::short(10, 28950, 0.025)->withTD(5)->build(),
                StopBuilder::short(20, 28540, 0.1)->withTD(5)->build(),
            ],
            '$stopsExpectedAfterHandle' => $initialStops,
        ];

        yield '[before position && $stop->price > $position->entryPrice - 100] && size < 0.025 => MUST be moved' => [
            '$initialPositionEntryPrice' => $initialPositionEntryPrice,
            '$newPositionEntryPrice' => $newPositionEntryPrice,
            '$initialStops' => $initialStops = [
                StopBuilder::short(10, 28950, 0.024)->withTD(5)->build(),
                StopBuilder::short(20, 28950, 0.001)->withTD(5)->build(),
                StopBuilder::short(30, 28900, 0.024)->withTD(5)->build(),
                StopBuilder::short(40, 28900, 0.001)->withTD(5)->build(),
            ],
            '$stopsExpectedAfterHandle' => $this->modifyStops(static fn(Stop $s) => $s->setPrice($s->getPrice() - $moveStep)->clearOriginalPrice(), ...$initialStops),
        ];

        // out of [$position->entryPrice - 100] range
        yield '[before position && price < $position->entryPrice - 100] && size < 0.025 => MUST NOT be moved' => [
            '$initialPositionEntryPrice' => $initialPositionEntryPrice,
            '$newPositionEntryPrice' => $newPositionEntryPrice,
            '$initialStops' => $initialStops = [
                StopBuilder::short(10, 28850, 0.024)->withTD(5)->build(),
                StopBuilder::short(20, 28850, 0.001)->withTD(5)->build(),
                StopBuilder::short(30, 28600, 0.024)->withTD(5)->build(),
                StopBuilder::short(40, 28600, 0.001)->withTD(5)->build(),
            ],
            '$stopsExpectedAfterHandle' => $initialStops,
        ];
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }

    /**
     * @return Stop[]
     */
    private function modifyStops(callable $modifier, Stop ...$stops): array
    {
        $result = [];

        foreach ($stops as $s) {
            $stop = new Stop($s->getId(), $s->getPrice(), $s->getVolume(), $s->getTriggerDelta(), $s->getPositionSide(), $s->getContext());
            $modifier($stop);

            $result[] = $stop;
        }

        return $result;
    }
}
