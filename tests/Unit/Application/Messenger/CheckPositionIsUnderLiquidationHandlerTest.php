<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Messenger;

use App\Application\Messenger\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\CheckPositionIsUnderLiquidationHandler;
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
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

/**
 * @group liquidation
 */
final class CheckPositionIsUnderLiquidationHandlerTest extends TestCase
{
    private const CRITICAL_LIQUIDATION_DELTA = CheckPositionIsUnderLiquidationHandler::CRITICAL_DELTA;

    private const DEFAULT_TRANSFER_AMOUNT = CheckPositionIsUnderLiquidationHandler::DEFAULT_TRANSFER_AMOUNT;
    private const TRANSFER_AMOUNT_DIFF_WITH_BALANCE = CheckPositionIsUnderLiquidationHandler::TRANSFER_AMOUNT_DIFF_WITH_BALANCE;
    private const ACCEPTABLE_POSITION_STOPS_PART_BEFORE_CRITICAL_RANGE = CheckPositionIsUnderLiquidationHandler::ACCEPTABLE_POSITION_STOPS_PART_BEFORE_CRITICAL_RANGE;

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

    public function testDoMakeInterTransferWhenPositionIsNotUnderLiquidation(): void
    {
        $this->havePosition(
            $position = new Position(Side::Sell, Symbol::BTCUSDT, 34000, 0.5, 20000, 35000, 200, 100),
        );

        $this->haveTicker(
            TickerFactory::create(Symbol::BTCUSDT, 34900, 35000 - self::CRITICAL_LIQUIDATION_DELTA - 1),
        );

        // Assert
        $this->exchangeAccountService->expects(self::never())->method('interTransferFromSpotToContract');
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

        $markPrice = $position->isShort() ? $liquidationPrice - self::CRITICAL_LIQUIDATION_DELTA : $liquidationPrice + self::CRITICAL_LIQUIDATION_DELTA;
        $ticker = TickerFactory::create($position->symbol, $position->isShort() ? $markPrice - 10 : $markPrice + 10, $markPrice);

        $this->havePosition($position);
        $this->haveTicker($ticker);

        $side = $position->side;
        $symbol = $position->symbol;
        $coin = $symbol->associatedCoin();

        $this->stopRepository->expects(self::once())->method('findActive')->with($position->side)->willReturn([]);
        $this->stopService->expects(self::never())->method(self::anything());

        $this->exchangeAccountService->expects(self::once())->method('getSpotWalletBalance')->with($coin)->willReturn(
            new WalletBalance(AccountType::SPOT, $coin, $spotAvailableBalance)
        );

        if ($expectedTransferAmount !== null) {
            $this->exchangeAccountService->expects(self::once())->method('interTransferFromSpotToContract')->with(
                $coin,
                $expectedTransferAmount
            );
        } else {
            $this->exchangeAccountService->expects(self::never())->method('interTransferFromSpotToContract');
        }

        $this->orderService
            ->expects(self::once())
            ->method('closeByMarket')
            ->with(
                $position,
                (new Percent(self::ACCEPTABLE_POSITION_STOPS_PART_BEFORE_CRITICAL_RANGE))->of($position->size)
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
            'expectedTransferAmount' => null
        ];

        yield 'transfer 15 usdt' => [
            'position' => $position,
            'spotAvailableBalance' => 100,
            'expectedTransferAmount' => self::DEFAULT_TRANSFER_AMOUNT,
        ];

        yield 'transfer all available' => [
            'position' => $position,
            'spotAvailableBalance' => $spotBalance = 7,
            'expectedTransferAmount' => $spotBalance - self::TRANSFER_AMOUNT_DIFF_WITH_BALANCE
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
