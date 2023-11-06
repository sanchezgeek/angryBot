<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Messenger;

use App\Application\Messenger\CheckPositionIsUnderLiquidation;
use App\Application\Messenger\CheckPositionIsUnderLiquidationHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use PHPUnit\Framework\TestCase;

final class CheckPositionIsUnderLiquidationHandlerTest extends TestCase
{
    private const DEFAULT_COIN_TRANSFER_AMOUNT = CheckPositionIsUnderLiquidationHandler::DEFAULT_COIN_TRANSFER_AMOUNT;

    private ExchangeServiceInterface $exchangeService;
    private PositionServiceInterface $positionService;
    private ExchangeAccountServiceInterface $exchangeAccountService;

    private CheckPositionIsUnderLiquidationHandler $handler;

    protected function setUp(): void
    {
        $this->exchangeService = $this->createMock(ExchangeServiceInterface::class);
        $this->positionService = $this->createMock(PositionServiceInterface::class);
        $this->exchangeAccountService = $this->createMock(ExchangeAccountServiceInterface::class);

        $this->handler = new CheckPositionIsUnderLiquidationHandler($this->exchangeService, $this->positionService, $this->exchangeAccountService);
    }

    public function testDoNothingWhenPositionIsNotUnderLiquidation(): void
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = new Position($side, $symbol, 34000, 0.5, 20000, 35000, 200, 100);
        $ticker = new Ticker($symbol, 34929, 34900, 'test');

        $this->positionService->expects(self::once())->method('getPosition')->with($symbol, $side)->willReturn($position);
        $this->exchangeService->expects(self::once())->method('ticker')->with($symbol)->willReturn($ticker);
        $this->exchangeAccountService->expects(self::never())->method('interTransferFromSpotToContract');

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

        ($this->handler)(new CheckPositionIsUnderLiquidation($symbol, $side));
    }

    public function makeInterTransferCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $side = Side::Sell;
        $position = new Position($side, $symbol, 34000, 0.5, 20000, 35000, 200, 100);

        yield 'transfer 15 usdt' => [
            '$position' => $position,
            '$ticker' => new Ticker($symbol, 34930, 34900, 'test'),
            '$spotAvailableBalance' => 100,
            '$expectedTransferAmount' => self::DEFAULT_COIN_TRANSFER_AMOUNT,
        ];

        yield 'transfer all available' => [
            '$position' => $position,
            '$ticker' => new Ticker($symbol, 34940, 34900, 'test'),
            '$spotAvailableBalance' => 7,
            '$expectedTransferAmount' => 7
        ];

        yield 'transfer all available [2]' => [
            '$position' => $position,
            '$ticker' => new Ticker($symbol, 34940, 34900, 'test'),
            '$spotAvailableBalance' => 0.1,
            '$expectedTransferAmount' => 0.1
        ];

        yield 'spot is empty' => [
            '$position' => $position,
            '$ticker' => new Ticker($symbol, 34940, 34900, 'test'),
            '$spotAvailableBalance' => 0,
            '$expectedTransferAmount' => null
        ];
    }
}
