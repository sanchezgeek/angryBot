<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Messenger\Position\CheckPositionIsUnderLiquidation;

use App\Application\Logger\AppErrorLoggerInterface;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation\CheckPositionIsUnderLiquidationHandler;
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
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Coin\CoinAmount;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Liquidation\Application\Settings\LiquidationHandlerSettings;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use App\Tests\Mixin\Logger\AppErrorsSymfonyLoggerTrait;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\Trading\TradingParametersMocker;
use App\Tests\Stub\TA\TradingParametersProviderStub;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function min;
use function sprintf;

/**
 * @group liquidation
 *
 * @covers CheckPositionIsUnderLiquidationHandler
 *
 * @todo functional?
 */
final class CheckPositionIsUnderLiquidationHandlerTest extends KernelTestCase
{
    use PositionSideAwareTest;
    use AppErrorsSymfonyLoggerTrait;
    use SettingsAwareTest;
    use TradingParametersMocker;

    private const int TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;
    private const int CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = CheckPositionIsUnderLiquidationHandler::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN;

    private const int MAX_TRANSFER_AMOUNT = CheckPositionIsUnderLiquidationHandler::MAX_TRANSFER_AMOUNT;
    private const int TRANSFER_AMOUNT_DIFF_WITH_BALANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_AMOUNT_DIFF_WITH_BALANCE;

    private const int DISTANCE_FOR_CALC_TRANSFER_AMOUNT = 300;

    private ExchangeServiceInterface $exchangeService;
    private PositionServiceInterface $positionService;
    private ExchangeAccountServiceInterface $exchangeAccountService;
    private StopServiceInterface $stopService;
    private OrderServiceInterface $orderService;
    private StopRepositoryInterface $stopRepository;
    private AppSettingsProviderInterface $settingsProvider;

    private CheckPositionIsUnderLiquidationHandler $handler;

    private TradingParametersProviderStub $tradingParametersProvider;

    protected function setUp(): void
    {
        $this->exchangeService = $this->createMock(ExchangeServiceInterface::class);
        $this->positionService = $this->createMock(PositionServiceInterface::class);
        $this->exchangeAccountService = $this->createMock(ExchangeAccountServiceInterface::class);
        $this->orderService = $this->createMock(OrderServiceInterface::class);
        $this->stopService = $this->createMock(StopServiceInterface::class);
        $this->stopRepository = $this->createMock(StopRepositoryInterface::class);
        $this->settingsProvider = $this->createMock(AppSettingsProviderInterface::class);

        self::createTradingParametersStub();

        $this->handler = new CheckPositionIsUnderLiquidationHandler(
            $this->exchangeService,
            $this->positionService,
            $this->exchangeAccountService,
            $this->orderService,
            $this->stopService,
            $this->stopRepository,
            self::getContainer()->get(AppErrorLoggerInterface::class),
            null,
            self::getContainerSettingsProvider(),
            self::getContainer()->get(LiquidationDynamicParametersFactory::class),
            self::DISTANCE_FOR_CALC_TRANSFER_AMOUNT
        );
    }

    /**
     * @dataProvider doNothingWhenPositionIsNotUnderLiquidationTestCases
     */
    public function testDoNothingWhenPositionIsNotUnderLiquidation(Ticker $ticker, Position $position): void
    {
        self::mockTradingParametersForLiquidationTests($ticker->symbol);

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
        $ticker = TickerFactory::create(SymbolEnum::BTCUSDT, $markPrice - 10, $markPrice, $markPrice - 10);
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
        self::mockTradingParametersForLiquidationTests($position->symbol);

        $liquidationPrice = $position->liquidationPrice;

        $closeByMarketIfDistanceLessThan = FloatHelper::modify(PnlHelper::convertPnlPercentOnPriceToAbsDelta(self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN, $position->entryPrice()), 0.1);

        $markPrice = $position->isShort() ? $liquidationPrice - $closeByMarketIfDistanceLessThan : $liquidationPrice + $closeByMarketIfDistanceLessThan;
        $ticker = TickerFactory::withEqualPrices($position->symbol, $markPrice);

        $this->havePositions($position);
        $this->haveTicker($ticker);

        $symbol = $position->symbol;
        $coin = $symbol->associatedCoin();

        $this->stopRepository->method('findActive')->withConsecutive(
            [$position->symbol, $position->side->getOpposite()],
            [$position->symbol, $position->side],
            [$position->symbol, $position->side],
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

        $acceptableStoppedPartBeforeLiquidation = self::getContainerSettingsProvider()->required(
            SettingAccessor::withAlternativesAllowed(LiquidationHandlerSettings::AcceptableStoppedPartOverride, $position->symbol, $position->side)
        );

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

            $spotBalance = $expectedAmountToTransferFromSpot->sub(2);
            yield sprintf('[%s] spotBalance is more than default min value => transfer min default value', $position) => [
                'position' => $position,
                'spotAvailableBalance' => $spotBalance->value(),
                'expectedTransferAmount' => $spotBalance->sub(self::TRANSFER_AMOUNT_DIFF_WITH_BALANCE)->value(),
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

        return $position->symbol->associatedCoinAmount($amount);
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
