<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\EventListener\Stop;

use App\Application\EventListener\Stop\FixMainHedgePositionListener;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Infrastructure\ByBit\Service\ByBitCommissionProvider;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

final class FixMainHedgePositionListenerTest extends TestCase
{
    private ExchangeServiceInterface $exchangeService;
    private PositionServiceInterface $positionService;
    private StopServiceInterface $stopService;
    private FixMainHedgePositionListener $listener;

    protected function setUp(): void
    {
        $this->exchangeService = $this->createMock(ExchangeServiceInterface::class);
        $this->positionService = $this->createMock(PositionServiceInterface::class);
        $this->stopService = $this->createMock(StopServiceInterface::class);

        $orderCostCalculator = new OrderCostCalculator(new ByBitCommissionProvider());
        $exchangeAccountService = $this->createMock(ExchangeAccountServiceInterface::class);

        $this->listener = new FixMainHedgePositionListener(
            $orderCostCalculator,
            $exchangeAccountService,
            $this->exchangeService,
            $this->positionService,
            $this->stopService,
        );
    }

    /**
     * @dataProvider cases
     */
    public function testAddedSupplyStopAfterSupportPositionInLossHasBeenClosedByMarket(
        Symbol$symbol,
        Position $stoppedPosition,
        Stop $executedStop,
        float $expectedSupplyStopPrice,
        float $expectedSupplyStopVolume,
    ): void {
        # common preconditions
        $executedStop->setIsCloseByMarketContext();
        $executedStop->setIsFixHedgeOnLossEnabled();
        # stop closed by market
        $ticker = TickerFactory::withEqualPrices($symbol, $executedStop->getPrice());

        $this->haveTicker($ticker);
        $this->haveStoppedSupportPosition($stoppedPosition);

//        $this->stopService->expects(self::once())->method('create')->willReturnCallback(
//            function (Symbol $symbol, Side $positionSide, Price|float $price, float $volume, ?float $triggerDelta, array $context = []) use ($main) {
//                var_dump($symbol, $positionSide, $price, $volume, $triggerDelta, $context, PnlHelper::getPnlInUsdt($main, $price, $volume));die;
//            }
//        );

        $this->stopService->expects(self::once())->method('create')->with(
            $symbol,
            $stoppedPosition->oppositePosition->side,
            $expectedSupplyStopPrice,
            $expectedSupplyStopVolume,
            null,
            [Stop::CLOSE_BY_MARKET_CONTEXT => true, Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true]
        );

        $event = new StopPushedToExchange($executedStop);
        ($this->listener)($event);
    }

    public function cases(): iterable
    {
        # support
        $symbol = Symbol::BTCUSDT;
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->build();
        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.5)->opposite($main)->build();
        $stop = new Stop(1, 62000, 0.1, null, $symbol, Side::Sell);
        yield 'BTCUSDT SHORT closed' => [$symbol, $support, $stop, 61752, 0.017];

        $main = PositionBuilder::short()->symbol($symbol)->entry(55000)->size(1)->build();
        $support = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.5)->opposite($main)->build();
        $stop = new Stop(1, 49000, 0.1, null, $symbol, Side::Buy);
        yield 'BTCUSDT LONG closed' => [$symbol, $support, $stop, 49196, 0.017];

        $symbol = Symbol::ADAUSDT;
        $main = PositionBuilder::long()->symbol($symbol)->entry(0.8336)->size(4470)->build();
        $support = PositionBuilder::short()->symbol($symbol)->entry(0.9052)->size(2700)->opposite($main)->build();
        $stop = new Stop(1, 0.9442, 10, null, $symbol, Side::Sell);
        yield 'ADAUSDT SHORT closed' => [$symbol, $support, $stop, 0.93, 4];

        $main = PositionBuilder::short()->symbol($symbol)->entry(0.8552)->size(4470)->build();
        $support = PositionBuilder::long()->symbol($symbol)->entry(0.8336)->size(2700)->opposite($main)->build();
        $stop = new Stop(1, 0.8200, 10, null, $symbol, Side::Buy);
        yield 'ADAUSDT LONG closed' => [$symbol, $support, $stop, 0.8323, 6];

        # main I (when entry prices equal)
        $symbol = Symbol::BTCUSDT;
        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.5)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->opposite($support)->build();
        $stop = new Stop(1, 48000, 0.1, null, $symbol, Side::Buy);
        yield 'I BTCUSDT LONG (main) closed (1)' => [$symbol, $main, $stop, 48192.0, 0.05];

        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.45)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.9)->opposite($support)->build();
        $stop = new Stop(1, 47000, 0.1, null, $symbol, Side::Buy);
        yield 'I BTCUSDT LONG (main) closed (2)' => [$symbol, $main, $stop, 47188.0, 0.05];

        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.4)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.8)->opposite($support)->build();
        $stop = new Stop(1, 46000, 0.1, null, $symbol, Side::Buy);
        yield 'I BTCUSDT LONG (main) closed (3)' => [$symbol, $main, $stop, 46184.0, 0.05];

        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.35)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.7)->opposite($support)->build();
        $stop = new Stop(1, 45000, 0.1, null, $symbol, Side::Buy);
        yield 'I BTCUSDT LONG (main) closed (4)' => [$symbol, $main, $stop, 45180.0, 0.05];

        // ....

        # main II (when entry prices equal)
        $support = PositionBuilder::short()->symbol($symbol)->entry(55000)->size(0.5)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->opposite($support)->build();
        $stop = new Stop(1, 48000, 0.1, null, $symbol, Side::Buy);
        yield 'II.1 BTCUSDT LONG (main) closed (1)' => [$symbol, $main, $stop, 48192.0, 0.029];

        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.5)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->opposite($support)->build();
        $stop = new Stop(1, 48000, 0.1, null, $symbol, Side::Buy);
        yield 'III.2 BTCUSDT LONG (main) closed (1)' => [$symbol, $main, $stop, 48192.0, 0.017];

        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.483)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.9)->opposite($support)->build();
        $stop = new Stop(1, 47000, 0.1, null, $symbol, Side::Buy);
        yield 'III.2 BTCUSDT LONG (main) closed (2)' => [$symbol, $main, $stop, 47188.0, 0.023];

        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.459)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.8)->opposite($support)->build();
        $stop = new Stop(1, 46000, 0.1, null, $symbol, Side::Buy);
        yield 'III.2 BTCUSDT LONG (main) closed (3)' => [$symbol, $main, $stop, 46184.0, 0.029];

        // @todo figure out what to do in the worst scenario: when main position must be totally closed, but support must remain
        // @todo for SHORT
    }

    private function haveStoppedSupportPosition(Position $position): void
    {
        $this->positionService->expects(self::once())->method('getPosition')->with($position->symbol)->willReturn($position);
    }

    public function haveTicker(Ticker $ticker): void
    {
        $this->exchangeService->expects(self::once())->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }
}
