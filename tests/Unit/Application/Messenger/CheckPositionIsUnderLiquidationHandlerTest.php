<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Messenger;

use App\Application\Messenger\Position\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\Position\CheckPositionIsUnderLiquidationHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

use function min;

/**
 * @group liquidation
 *
 * @covers CheckPositionIsUnderLiquidationHandler
 */
final class CheckPositionIsUnderLiquidationHandlerTest extends TestCase
{
    private const TRANSFER_FROM_SPOT_ON_DISTANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_FROM_SPOT_ON_DISTANCE;
    private const CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN = CheckPositionIsUnderLiquidationHandler::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN;

    private const DEFAULT_TRANSFER_AMOUNT = CheckPositionIsUnderLiquidationHandler::MIN_TRANSFER_AMOUNT;
    private const TRANSFER_AMOUNT_DIFF_WITH_BALANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_AMOUNT_DIFF_WITH_BALANCE;
    private const ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION = CheckPositionIsUnderLiquidationHandler::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION;

    private ExchangeServiceInterface $exchangeService;
    private PositionServiceInterface $positionService;
    private ExchangeAccountServiceInterface $exchangeAccountService;
    private StopServiceInterface $stopService;
    private OrderServiceInterface $orderService;
    private StopRepositoryInterface $stopRepository;

    private CheckPositionIsUnderLiquidationHandler $handler;

    protected function setUp(): void
    {
        $this->exchangeService = $this->createMock(ExchangeServiceInterface::class);
        $this->positionService = $this->createMock(PositionServiceInterface::class);
        $this->exchangeAccountService = $this->createMock(ExchangeAccountServiceInterface::class);
        $this->stopService = $this->createMock(StopServiceInterface::class);
        $this->orderService = $this->createMock(OrderServiceInterface::class);
        $this->stopRepository = $this->createMock(StopRepositoryInterface::class);

        $this->handler = new CheckPositionIsUnderLiquidationHandler(
            $this->exchangeService,
            $this->positionService,
            $this->exchangeAccountService,
            $this->orderService,
            $this->stopService,
            $this->stopRepository,
        );
    }

    public function testDoNothingWhenPositionIsNotUnderLiquidation(): void
    {
        $markPrice = 35000;
        $liquidationPrice = $markPrice + self::TRANSFER_FROM_SPOT_ON_DISTANCE + 1;

        $position = new Position(Side::Sell, Symbol::BTCUSDT, 34000, 0.5, 20000, $liquidationPrice, 200, 100);
        $ticker = TickerFactory::create(Symbol::BTCUSDT, $markPrice - 10, $markPrice, $markPrice - 10);

        $this->havePosition($position);
        $this->haveTicker($ticker);

        // Assert
        $this->exchangeAccountService->expects(self::never())->method(self::anything());
        $this->orderService->expects(self::never())->method(self::anything());

        // Act
        ($this->handler)(new CheckPositionIsUnderLiquidation($position->symbol, $position->side));
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

        $markPrice = $position->isShort() ? $liquidationPrice - self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN : $liquidationPrice + self::CLOSE_BY_MARKET_IF_DISTANCE_LESS_THAN;
        $ticker = TickerFactory::create($position->symbol, $position->isShort() ? $markPrice - 10 : $markPrice + 10, $markPrice);

        $this->havePosition($position);
        $this->haveTicker($ticker);

        $side = $position->side;
        $symbol = $position->symbol;
        $coin = $symbol->associatedCoin();

        $this->stopRepository->expects(self::once())->method('findActive')->with($position->side)->willReturn([]);
        $this->stopService->expects(self::never())->method(self::anything());

        if ($spotAvailableBalance > 2) {
            $this->exchangeAccountService->expects(self::exactly(2))->method('getSpotWalletBalance')->with($coin)->willReturnOnConsecutiveCalls(
                new WalletBalance(AccountType::SPOT, $coin, $spotAvailableBalance, $spotAvailableBalance), // old
                new WalletBalance(AccountType::SPOT, $coin, $spotAvailableBalance - $expectedTransferAmount, $spotAvailableBalance - $expectedTransferAmount) // new
            );
        }

        if ($expectedTransferAmount !== null) {
            $this->exchangeAccountService->expects(self::once())->method('interTransferFromSpotToContract')->with($coin, $expectedTransferAmount);
        } else {
            $this->exchangeAccountService->expects(self::never())->method('interTransferFromSpotToContract');
        }

        $this->orderService
            ->expects(self::once())
            ->method('closeByMarket')
            ->with(
                $position,
                (new Percent(self::ACCEPTABLE_STOPPED_PART_BEFORE_LIQUIDATION))->of($position->size),
            )
        ;

        ($this->handler)(new CheckPositionIsUnderLiquidation($symbol, $side));
    }

    public function makeInterTransferFromSpotAndCloseByMarketTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = new Position($side, $symbol, 34000, 0.5, 20000, 35000, 200, 100);

        yield 'spot is empty' => [
            'position' => $position,
            'spotAvailableBalance' => 0.2,
            'expectedTransferAmount' => null,
        ];

        yield 'spotBalance is more than default min value => transfer min default value' => [
            'position' => $position,
            'spotAvailableBalance' => self::DEFAULT_TRANSFER_AMOUNT + 2,
            'expectedTransferAmount' => self::DEFAULT_TRANSFER_AMOUNT,
        ];

        yield 'spotBalance is less than default min value => transfer all available' => [
            'position' => $position,
            'spotAvailableBalance' => $spotBalance = self::DEFAULT_TRANSFER_AMOUNT - 2,
            'expectedTransferAmount' => PriceHelper::round($spotBalance - self::TRANSFER_AMOUNT_DIFF_WITH_BALANCE),
        ];
    }

    private function havePosition(Position $position): void
    {
        $this->positionService->expects(self::once())->method('getPosition')->with($position->symbol, $position->side)->willReturn($position);
    }

    public function haveTicker(Ticker $ticker): void
    {
        $this->exchangeService->expects(self::once())->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }
}
