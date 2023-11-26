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
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Application\Service\Orders\StopServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Factory\TickerFactory;
use PHPUnit\Framework\TestCase;

final class CheckPositionIsUnderLiquidationHandlerTest extends TestCase
{

    private const DEFAULT_COIN_TRANSFER_AMOUNT = CheckPositionIsUnderLiquidationHandler::DEFAULT_COIN_TRANSFER_AMOUNT;

    private ExchangeServiceInterface $exchangeService;
    private PositionServiceInterface $positionService;
    private ExchangeAccountServiceInterface $exchangeAccountService;
    private StopServiceInterface $stopService;
    private OrderServiceInterface $orderService;
    private StopRepository $stopRepository;

    private CheckPositionIsUnderLiquidationHandler $handler;

    protected function setUp(): void
    {
        $this->exchangeService = $this->createMock(ExchangeServiceInterface::class);
        $this->positionService = $this->createMock(PositionServiceInterface::class);
        $this->exchangeAccountService = $this->createMock(ExchangeAccountServiceInterface::class);
        $this->stopService = $this->createMock(StopServiceInterface::class);
        $this->orderService = $this->createMock(OrderServiceInterface::class);
//        $this->stopRepository = $this->createMock()

        $this->handler = new CheckPositionIsUnderLiquidationHandler(
            $this->exchangeService,
            $this->positionService,
            $this->exchangeAccountService,
            $this->orderService,
            $this->stopService,

        );

        $this->stopService->expects(self::never())->method(self::anything());
    }

    public function testDoNothingWhenPositionIsNotUnderLiquidation(): void
    {
        $warningDelta = CheckPositionIsUnderLiquidationHandler::WARNING_LIQUIDATION_DELTA;

        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = new Position($side, $symbol, 34000, 0.5, 20000, 35000, 200, 100);
        $ticker = TickerFactory::create($symbol, 34900,35000 - $warningDelta - 1);

        $this->positionService->expects(self::once())->method('getPosition')->with($symbol, $side)->willReturn($position);
        $this->exchangeService->expects(self::once())->method('ticker')->with($symbol)->willReturn($ticker);
        $this->exchangeAccountService->expects(self::never())->method('interTransferFromSpotToContract');

        $this->orderService->expects(self::never())->method(self::anything());

        ($this->handler)(new CheckPositionIsUnderLiquidation($symbol, $side));
    }

    /**
     * @dataProvider makeInterTransferCases
     */
    public function testMakeInterTransferFromSpotWhenPositionIsUnderLiquidation(
        Position $position,
        Ticker $ticker,
        float $spotAvailableBalance,
        ?float $expectedTransferAmount,
    ): void {
        $side = $position->side;
        $symbol = $position->symbol;
        $coin = $symbol->associatedCoin();

        $this->positionService->expects(self::once())->method('getPosition')->with($symbol, $side)->willReturn($position);
        $this->exchangeService->expects(self::once())->method('ticker')->with($symbol)->willReturn($ticker);

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

        if (abs($position->liquidationPrice - $ticker->markPrice->value()) <= 40) {
            $this->orderService->expects(self::once())->method('closeByMarket')->with($position, Percent::string('10%')->of($position->size));
        } else {
            $this->orderService->expects(self::never())->method(self::anything());
        }

        ($this->handler)(new CheckPositionIsUnderLiquidation($symbol, $side));
    }

    public function makeInterTransferCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = new Position($side, $symbol, 34000, 0.5, 20000, 35000, 200, 100);
        $warningDelta = CheckPositionIsUnderLiquidationHandler::WARNING_LIQUIDATION_DELTA;
        $criticalDelta = CheckPositionIsUnderLiquidationHandler::CRITICAL_LIQUIDATION_DELTA;

        yield 'spot is empty' => [
            '$position' => $position,
            '$ticker' => TickerFactory::create($symbol, 34900,$position->liquidationPrice - $warningDelta),
            '$spotAvailableBalance' => 0,
            '$expectedTransferAmount' => null
        ];

        yield 'transfer 15 usdt' => [
            '$position' => $position,
            '$ticker' => TickerFactory::create($symbol, 34900,$position->liquidationPrice - $warningDelta),
            '$spotAvailableBalance' => 100,
            '$expectedTransferAmount' => self::DEFAULT_COIN_TRANSFER_AMOUNT,
        ];

        yield 'transfer all available' => [
            '$position' => $position,
            '$ticker' => TickerFactory::create($symbol, 34900,$position->liquidationPrice - $warningDelta / 2),
            '$spotAvailableBalance' => 7,
            '$expectedTransferAmount' => 7
        ];

        yield 'transfer all available [2]' => [
            '$position' => $position,
            '$ticker' => TickerFactory::create($symbol, 34900,$position->liquidationPrice - $warningDelta / 2),
            '$spotAvailableBalance' => 0.1,
            '$expectedTransferAmount' => 0.1
        ];

        yield 'critical => transfer all available [2] + close some position part' => [
            '$position' => $position,
            '$ticker' => TickerFactory::create($symbol, 34900,$position->liquidationPrice - $criticalDelta),
            '$spotAvailableBalance' => 0.1,
            '$expectedTransferAmount' => 0.1
        ];
    }
}
