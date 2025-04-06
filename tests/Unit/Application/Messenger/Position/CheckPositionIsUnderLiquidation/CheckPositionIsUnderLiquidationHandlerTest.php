<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationHandler;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationParams;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\DynamicParameters\LiquidationDynamicParametersFactory;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\Logger\AppErrorsLoggerTrait;
use PHPUnit\Framework\TestCase;

use function min;
use function sprintf;

/**
 * @group liquidation
 *
 * @covers CheckPositionIsUnderLiquidationHandler
 *
 * @todo functional?
 */
final class CheckPositionIsUnderLiquidationHandlerTest extends TestCase
{
    use PositionSideAwareTest;
    use AppErrorsLoggerTrait;

    private const TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;
    private const CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = CheckPositionIsUnderLiquidationHandler::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN;

    private const MAX_TRANSFER_AMOUNT = CheckPositionIsUnderLiquidationHandler::MAX_TRANSFER_AMOUNT;
    private const TRANSFER_AMOUNT_DIFF_WITH_BALANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_AMOUNT_DIFF_WITH_BALANCE;
    private const ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION_DEFAULT = CheckPositionIsUnderLiquidationParams::ACCEPTABLE_STOPPED_PART_DEFAULT;

    private ExchangeServiceInterface $exchangeService;
    private PositionServiceInterface $positionService;
    private ExchangeAccountServiceInterface $exchangeAccountService;
    private StopServiceInterface $stopService;
    private OrderServiceInterface $orderService;
    private StopRepositoryInterface $stopRepository;

    private CheckPositionIsUnderLiquidationHandler $handler;

    private const DISTANCE_FOR_CALC_TRANSFER_AMOUNT = 300;

    protected function setUp(): void
    {
        $this->exchangeService = $this->createMock(ExchangeServiceInterface::class);
        $this->positionService = $this->createMock(PositionServiceInterface::class);
        $this->exchangeAccountService = $this->createMock(ExchangeAccountServiceInterface::class);
        $this->orderService = $this->createMock(OrderServiceInterface::class);
        $this->stopService = $this->createMock(StopServiceInterface::class);
        $this->stopRepository = $this->createMock(StopRepositoryInterface::class);

        $this->handler = new CheckPositionIsUnderLiquidationHandler(
            $this->exchangeService,
            $this->positionService,
            $this->exchangeAccountService,
            $this->orderService,
            $this->stopService,
            $this->stopRepository,
            self::getTestAppErrorsLogger(),
            null,
            new LiquidationDynamicParametersFactory(),
            self::DISTANCE_FOR_CALC_TRANSFER_AMOUNT
        );
    }

    /**
     * @dataProvider doNothingWhenPositionIsNotUnderLiquidationTestCases
     */
    public function testDoNothingWhenPositionIsNotUnderLiquidation(Ticker $ticker, Position $position): void
    {
        $this->havePositions($position);
        $this->haveTicker($ticker);

        // Assert
        $this->exchangeAccountService->expects(self::never())->method(self::anything());
        $this->orderService->expects(self::never())->method(self::anything());

        // Act
        ($this->handler)(new CheckPositionIsUnderLiquidation($position->symbol));
    }

    public function doNothingWhenPositionIsNotUnderLiquidationTestCases(): iterable
    {
        $markPrice = 35000;
        $ticker = TickerFactory::create(Symbol::BTCUSDT, $markPrice - 10, $markPrice, $markPrice - 10);
        $transferFromSpotOnDistance = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::TRANSFER_FROM_SPOT_ON_DISTANCE, $ticker->indexPrice), 0.1);

        yield 'SHORT' => [
            '$ticker' => $ticker,
            '$position' => PositionBuilder::short()->entry(34000)->liq($markPrice + $transferFromSpotOnDistance + 1)->build(),
        ];

        yield 'LONG' => [
            '$ticker' => $ticker,
            '$position' => PositionBuilder::long()->entry(36000)->liq($markPrice - $transferFromSpotOnDistance - 1)->build(),
        ];
    }

    /**
     * @dataProvider makeInterTransferFromSpotAndCloseByMarketTestCases
     */
    public function testMakeInterTransferFromSpotAndCloseByMarket(
        Position $position,
        float $spotAvailableBalance,
        ?float $expectedTransferAmount,
    ): void {
        $liquidationPrice = $position->liquidationPrice;

        $closeByMarketIfDistanceLessThan = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN, $position->entryPrice()), 0.1);

        $markPrice = $position->isShort() ? $liquidationPrice - $closeByMarketIfDistanceLessThan : $liquidationPrice + $closeByMarketIfDistanceLessThan;
        $ticker = TickerFactory::withEqualPrices($position->symbol, $markPrice);

        $this->havePositions($position);
        $this->haveTicker($ticker);

        $symbol = $position->symbol;
        $coin = $symbol->associatedCoin();

        $this->stopRepository->method('findActive')->withConsecutive(
            [$position->symbol, $position->side],
            [$position->symbol, $position->side->getOpposite()],
        )->willReturn([]);

        $this->stopService->expects(self::never())->method(self::anything());

        $this->exchangeAccountService->expects(self::once())->method('getSpotWalletBalance')->with($coin)->willReturn(
            new SpotBalance($coin, $spotAvailableBalance, $spotAvailableBalance),
        );

        if ($expectedTransferAmount !== null) {
            $this->exchangeAccountService->expects(self::once())->method('interTransferFromSpotToContract')->with($coin, $expectedTransferAmount);
        } else {
            $this->exchangeAccountService->expects(self::never())->method('interTransferFromSpotToContract');
        }

        $acceptableStoppedPartBeforeLiquidation = self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION_DEFAULT;

        $this->orderService
            ->expects(self::once())
            ->method('closeByMarket')
            ->with(
                $position,
                $symbol->roundVolumeUp((new Percent($acceptableStoppedPartBeforeLiquidation))->of($position->size)),
            )
        ;

        ($this->handler)(new CheckPositionIsUnderLiquidation(symbol: $symbol, acceptableStoppedPart: $acceptableStoppedPartBeforeLiquidation));
    }

    public function makeInterTransferFromSpotAndCloseByMarketTestCases(): iterable
    {
        foreach ($this->positionSides() as $side) {
            $position = PositionBuilder::bySide($side)->size(0.1)->entry(34000)->liqDistance(1500)->build();
            $expectedAmountToTransferFromSpot = self::getExpectedAmountToTransferFromSpot($position);

            yield sprintf('[%s] spot is empty', $position) => [
                'position' => $position,
                'spotAvailableBalance' => 0.2,
                'expectedTransferAmount' => null,
            ];

            yield sprintf('[%s] spotBalance is more than default min value => transfer min default value', $position) => [
                'position' => $position,
                'spotAvailableBalance' => $expectedAmountToTransferFromSpot->add(2)->value(),
                'expectedTransferAmount' => $expectedAmountToTransferFromSpot->value(),
            ];

            $spotBalance = $expectedAmountToTransferFromSpot->sub(2.22);
            yield sprintf('[%s] spotBalance is less than default min value => transfer all available', $position) => [
                'position' => $position,
                'spotAvailableBalance' => $spotBalance->value(),
                'expectedTransferAmount' => $spotBalance->sub(self::TRANSFER_AMOUNT_DIFF_WITH_BALANCE)->value(),
            ];

            $position = PositionBuilder::bySide($side)->size(0.3)->entry(34000)->liqDistance(1500)->build();
            yield sprintf('[%s] position size too big => transfer amount must be cut down to MAX_TRANSFER_AMOUNT', $position) => [
                'position' => $position,
                'spotAvailableBalance' => self::MAX_TRANSFER_AMOUNT + 2,
                'expectedTransferAmount' => self::MAX_TRANSFER_AMOUNT,
            ];
        }
    }

    private static function getExpectedAmountToTransferFromSpot(Position $position): CoinAmount
    {
        $amount = min(self::DISTANCE_FOR_CALC_TRANSFER_AMOUNT * $position->getNotCoveredSize(), 60);

        return (new CoinAmount($position->symbol->associatedCoin(), $amount));
    }

    private function havePositions(Position ...$positions): void
    {
        $this->positionService->expects(self::once())->method('getPositions')->with($positions[0]->symbol)->willReturn($positions);
    }

    public function haveTicker(Ticker $ticker): void
    {
        $this->exchangeService->expects(self::once())->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }
}
