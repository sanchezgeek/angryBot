<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantStopOrders;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushRelevantStopsHandler;
use App\Bot\Application\Service\Orders\BuyOrderService;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Fixture\StopFixture;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function uuid_create;

/**
 * @covers PushRelevantStopsHandler
 */
final class PushRelevantBtcUsdtStopsHandlerTest extends AbstractOrderPushHandlerTest
{
    private const SYMBOL = Symbol::BTCUSDT;
    private const SIDE = Side::Sell;

    private PushRelevantStopsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BuyOrderService $buyOrderService */
        $buyOrderService = self::getContainer()->get(BuyOrderService::class);

        $this->handler = new PushRelevantStopsHandler($this->hedgeService, $this->stopRepository, $buyOrderService, $this->stopService, $this->messageBus, $this->eventDispatcher, $this->exchangeServiceMock, $this->positionServiceMock, $this->loggerMock, $this->clockMock, 0);
    }

    public function successPushCasesProvider(): iterable
    {
        yield 'by trigger delta' => [
            'currentIndex' => 29050,
            'fixtures' => [
                StopFixture::short(100500, 29060)->withTriggerDelta(10),
                StopFixture::short(200500, 29055)->withTriggerDelta(5),
            ]
        ];
    }

    /**
     * @dataProvider successPushCasesProvider
     */
    public function testCanPushStopOrdersToExchange(float $symbolIndexPrice, array $stops): void
    {
        // Arrange
        $this->applyDbFixtures(...$stops);

        $this->haveTicker(self::SYMBOL, $symbolIndexPrice);
        $this->havePosition(self::SYMBOL, self::SIDE, $symbolIndexPrice - 100);

        $this->positionServiceMock
            ->expects(self::exactly(count($stops)))
            ->method('addStop')
            ->willReturnCallback([$this, 'pushStopToExchange']);

        // Act
        ($this->handler)(new PushRelevantStopOrders(self::SYMBOL, self::SIDE));

        // Assert
        $this->assertStopsPushed(...$stops);
    }

    public function pushStopToExchange(Position $position, Ticker $ticker, float $price, float $volume): ?string
    {
        $exchangeOrderId = uuid_create();
        $this->positionServiceCalls[$exchangeOrderId] = [$position, $ticker, $price, $volume];

        return $exchangeOrderId;
    }

    private function assertStopsPushed(StopFixture ...$stops): void
    {
        $expectedCalls = array_map(
            fn(StopFixture $s) => [$this->position, $this->ticker, $s->getPrice(), $s->getVolume()],
            $stops
        );

        self::assertSame($expectedCalls, array_values($this->positionServiceCalls));

        $exchangeOrderIds = array_keys($this->positionServiceCalls);

        foreach ($stops as $key => $stopFixture) {
            $stop = $this->stopRepository->find($stopFixture->getId());
            self::assertSame($exchangeOrderIds[$key], $stop->getExchangeOrderId());
        }
    }
}
