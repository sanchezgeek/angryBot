<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\EventListener\Stop;

use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Stop\Event\StopPushedToExchange;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Infrastructure\ByBit\Service\ByBitCommissionProvider;
use App\Settings\Application\Service\SettingAccessor;
use App\Stop\Application\EventListener\FixOppositePositionListener;
use App\Stop\Application\Settings\FixOppositePositionSettings;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\Tests\TestCaseDescriptionHelper;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FixMainHedgePositionListenerTest extends KernelTestCase
{
    use SettingsAwareTest;

    const APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN_DEFAULT = 200;

    private ExchangeServiceInterface $exchangeService;
    private PositionServiceInterface $positionService;
    private StopServiceInterface $stopService;
    private FixOppositePositionListener $listener;

    protected function setUp(): void
    {
        $this->exchangeService = $this->createMock(ExchangeServiceInterface::class);
        $this->positionService = $this->createMock(PositionServiceInterface::class);
        $this->stopService = $this->createMock(StopServiceInterface::class);

        $orderCostCalculator = new OrderCostCalculator(new ByBitCommissionProvider());
        $exchangeAccountService = $this->createMock(ExchangeAccountServiceInterface::class);

        $this->overrideSetting(FixOppositePositionSettings::FixOppositePosition_If_OppositePositionPnl_GreaterThan, sprintf('%d%%', self::APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN_DEFAULT));

        $this->listener = new FixOppositePositionListener(
            self::getContainerSettingsProvider(),
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
    public function testSupplyStopAdded(
        Position $stoppedPosition,
        Stop $executedStop,
        float $expectedSupplyStopPrice,
        float $expectedSupplyStopVolume,
    ): void {
        $symbol = $stoppedPosition->symbol;
        $ticker = TickerFactory::withEqualPrices($symbol, $executedStop->getPrice());

        # stop must be closed by market
        $executedStop->setIsCloseByMarketContext();

        # to add supply stop corresponding flag must be set
        if ($stoppedPosition->isSupportPosition()) {
            $executedStop->enableFixOppositeMainOnLoss();
        } else {
            $executedStop->enableFixOppositeSupportOnLoss();
        }

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
            [
                Stop::CLOSE_BY_MARKET_CONTEXT => true,
                Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true,
                Stop::CREATED_AFTER_FIX_HEDGE_OPPOSITE_POSITION => true,
            ]
        );

        $event = new StopPushedToExchange($executedStop);
        ($this->listener)($event);
    }

    /**
     * @dataProvider cases
     */
    public function testSupplyStopNotAddedIfFLagIsNotSet(
        Position $stoppedPosition,
        Stop $executedStop,
    ): void {
        $symbol = $stoppedPosition->symbol;
        $ticker = TickerFactory::withEqualPrices($symbol, $executedStop->getPrice());

        # stop must be closed by market
        $executedStop->setIsCloseByMarketContext();
        # to add supply stop corresponding flag must NOT be set (so skip)

        $this->haveTicker($ticker);
        $this->haveStoppedSupportPosition($stoppedPosition);

        $this->stopService->expects(self::never())->method('create');

        $event = new StopPushedToExchange($executedStop);
        ($this->listener)($event);
    }

    public function cases(): iterable
    {
        # BTCUSDT support closed
        $symbol = Symbol::BTCUSDT;

        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->build();
        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.5)->opposite($main)->build();
        $stop = (new Stop(1, 62000, 0.1, null, $symbol, $support->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($support, $stop);
        yield self::caseDescription($support, $stop, $expectedStopPrice, $expectedStopVolume) => [$support, $stop, $expectedStopPrice, $expectedStopVolume];

        $main = PositionBuilder::short()->symbol($symbol)->entry(55000)->size(1)->build();
        $support = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.5)->opposite($main)->build();
        $stop = (new Stop(1, 49000, 0.1, null, $symbol, $support->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($support, $stop);
        yield self::caseDescription($support, $stop, $expectedStopPrice, $expectedStopVolume) => [$support, $stop, $expectedStopPrice, $expectedStopVolume];

        # ADAUSDT support
        $symbol = Symbol::ADAUSDT;
        $main = PositionBuilder::long()->symbol($symbol)->entry(0.8336)->size(4470)->build();
        $support = PositionBuilder::short()->symbol($symbol)->entry(0.9052)->size(2700)->opposite($main)->build();
        $stop = (new Stop(1, 0.9442, 10, null, $symbol, $support->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($support, $stop);
        yield self::caseDescription($support, $stop, $expectedStopPrice, $expectedStopVolume) => [$support, $stop, $expectedStopPrice, $expectedStopVolume];

        $main = PositionBuilder::short()->symbol($symbol)->entry(0.8552)->size(4470)->build();
        $support = PositionBuilder::long()->symbol($symbol)->entry(0.8336)->size(2700)->opposite($main)->build();
        $stop = (new Stop(1, 0.8200, 10, null, $symbol, $support->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($support, $stop);
        yield self::caseDescription($support, $stop, $expectedStopPrice, $expectedStopVolume) => [$support, $stop, $expectedStopPrice, $expectedStopVolume];

        # BTCUSDT main closed
        ### -- entry prices equal
        $symbol = Symbol::BTCUSDT;
        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.5)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->opposite($support)->build();
        $stop = (new Stop(1, 48000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'I (when entry prices equal) step by step (1)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.45)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.9)->opposite($support)->build();
        $stop = (new Stop(1, 47000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'I (when entry prices equal) step by step (2)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.4)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.8)->opposite($support)->build();
        $stop = (new Stop(1, 46000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'I (when entry prices equal) step by step (3)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        $support = PositionBuilder::short()->symbol($symbol)->entry(50000)->size(0.35)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.7)->opposite($support)->build();
        $stop = (new Stop(1, 45000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'I (when entry prices equal) step by step (4)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        // .... 5, 6, 7 ....

        ### -- II (some distance)
        $support = PositionBuilder::short()->symbol($symbol)->entry(55000)->size(0.5)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->opposite($support)->build();
        $stop = (new Stop(1, 48000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'II (some distance)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.5)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(1)->opposite($support)->build();
        $stop = (new Stop(1, 48000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'III (some distance) step by step (1)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.483)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.9)->opposite($support)->build();
        $stop = (new Stop(1, 47000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'III (some distance) step by step (2)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        $support = PositionBuilder::short()->symbol($symbol)->entry(60000)->size(0.459)->build();
        $main = PositionBuilder::long()->symbol($symbol)->entry(50000)->size(0.8)->opposite($support)->build();
        $stop = (new Stop(1, 46000, 0.1, null, $symbol, $main->side));
        [$expectedStopPrice, $expectedStopVolume] = self::expectedStopPriceAndDistance($main, $stop);
        yield self::caseDescription($main, $stop, $expectedStopPrice, $expectedStopVolume, 'III (some distance) step by step (3)') => [$main, $stop, $expectedStopPrice, $expectedStopVolume];

        // @todo figure out what to do in the worst scenario: when main position must be totally closed, but support must remain
        // @todo for SHORT
    }

    private function haveStoppedSupportPosition(Position $position): void
    {
        $this->positionService->method('getPosition')->with($position->symbol)->willReturn($position);
    }

    private function haveTicker(Ticker $ticker): void
    {
        $this->exchangeService->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }

    private static function caseDescription(
        Position $stoppedPosition,
        Stop $executedStop,
        float $expectedSupplyStopPrice,
        float $expectedSupplyStopVolume,
        ?string $additionalInfo = null
    ): string {
        return sprintf(
            "\n[%s%s closed] / stop.price = %.2f => add %s stop on %s for %s",
            $additionalInfo ? sprintf('%s / ', $additionalInfo) : '',
            TestCaseDescriptionHelper::getPositionCaption($stoppedPosition),
            $executedStop->getPrice(),
            $expectedSupplyStopVolume,
            $expectedSupplyStopPrice,
            TestCaseDescriptionHelper::getPositionCaption($stoppedPosition->oppositePosition),
        );
    }

    private static function expectedStopPriceAndDistance(Position $stoppedPosition, Stop $executedStop): array
    {
        $symbol = $stoppedPosition->symbol;
        $oppositePosition = $stoppedPosition->oppositePosition;
        $stopPrice = $executedStop->getPrice();
        $positionSide = $executedStop->getPositionSide();
        $closedVolume = $executedStop->getVolume();

        $supplyStopPnlDistancePct = self::getContainerSettingsProvider()->required(
            SettingAccessor::withAlternativesAllowed(FixOppositePositionSettings::FixOppositePosition_supplyStopPnlDistance, $symbol, $positionSide)
        );

        $distance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta($supplyStopPnlDistancePct, $symbol->makePrice($stopPrice)), 0.1);
        $supplyStopPrice = $symbol->makePrice(
            $stoppedPosition->isLong() ? $stopPrice + $distance : $stopPrice - $distance
        )->value();

        $loss = abs($executedStop->getPnlUsd($stoppedPosition));

        $oppositePositionStopVolume = PnlHelper::getVolumeForGetWishedProfit($loss, $oppositePosition->entryPrice()->deltaWith($supplyStopPrice));
        if ($stoppedPosition->isMainPosition()) {
            // otherwise fixed support volume occasionally might be not enough to cover all losses of main position
            $stoppedPart = Percent::fromPart($closedVolume / $stoppedPosition->size);
            $maximalVolumeOfOppositePositionToClose = $stoppedPart->of($oppositePosition->size);

            if ($oppositePositionStopVolume > $maximalVolumeOfOppositePositionToClose) {
                $oppositePositionStopVolume = $maximalVolumeOfOppositePositionToClose;
            }
        }

        $oppositePositionStopVolume = $symbol->roundVolume($oppositePositionStopVolume);

        return [$supplyStopPrice, $oppositePositionStopVolume];
    }
}
