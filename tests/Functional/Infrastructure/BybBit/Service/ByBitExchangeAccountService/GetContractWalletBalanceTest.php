<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitExchangeAccountService;

use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mock\Response\ByBitV5Api\Account\GetWalletBalanceResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService::getContractWalletBalance
 */
final class GetContractWalletBalanceTest extends ByBitExchangeAccountServiceTestAbstract
{
    /**
     * @dataProvider getContractWalletBalanceSuccessTestCases
     */
    public function testCanGetContractWalletBalance(
        Coin $coin,
        array $positions,
        MockResponse $apiResponse,
        ContractBalance $expectedContractBalance,
        ?Ticker $ticker = null,
    ): void {
        $this->matchGet(new GetWalletBalanceRequest(AccountType::UNIFIED, $coin), $apiResponse);
        $this->havePosition(Symbol::BTCUSDT, ...$positions);
        if ($ticker) $this->haveTicker($ticker);

        // Act
        $contractBalance = $this->service->getContractWalletBalance($coin);

        // Assert
        self::assertEquals($expectedContractBalance, $contractBalance);
    }

    private function getContractWalletBalanceSuccessTestCases(): iterable
    {
        ### USDT
        $coin = Coin::USDT;

// --- without positions opened --- //
    # arrange
        $total = 600.9;
        $totalPositionIM = 0;
    # assert
        $expectedAvailable = $total;
    # case
        yield sprintf('                    %s UNIFIED: %s `total` | %s `totalPositionIM` => expected %s available', $coin->value, $total, $totalPositionIM, $expectedAvailable) => [
            '$coin' => $coin,
            '$positions' => [],
            '$apiResponse' => GetWalletBalanceResponseBuilder::ok()->withUnifiedBalance($coin, $total, $totalPositionIM)->build(),
            'expectedContractBalance' => new ContractBalance($coin, $total, $total, $total, $total),
        ]; unset($total, $totalPositionIM, $expectedAvailable);

// --- with only short is opened --- //
    # arrange
        $ticker = TickerFactory::create(Symbol::BTCUSDT, 94324, 94324, 94324);
        $short = PositionFactory::short(Symbol::BTCUSDT, 93281.22, 0.776, 100, 115812.71);
        $total = 17875.96740505;
        $totalPositionIM = 764.57558218;
    # assert
        $expectedAvailable = 16302.194542869998;
        $expectedFree = $total - $totalPositionIM;
        $expectedFreeForLiquidation = 17122.50504;
    # case
        yield sprintf('[with short opened] %s UNIFIED: %s `total` | %s `totalPositionIM` => expected %s free | %s available | %s freeForLiquidation', $coin->value, $total, $totalPositionIM, $expectedFree, $expectedAvailable, $expectedFreeForLiquidation) => [
            '$coin' => $coin,
            '$positions' => [$short],
            '$apiResponse' => GetWalletBalanceResponseBuilder::ok()->withUnifiedBalance($coin, $total, $totalPositionIM)->build(),
            'expectedContractBalance' => new ContractBalance($coin, $total, $expectedAvailable, $expectedFree, $expectedFreeForLiquidation),
            '$ticker' => $ticker,
        ]; unset($total, $totalPositionIM, $expectedAvailable, $expectedFree, $expectedFreeForLiquidation);

// --- with hedge is opened and there is some free balance --- //
    # arrange
        $ticker = TickerFactory::create(Symbol::BTCUSDT, 94324, 94324, 94324);
        $short = PositionFactory::short(Symbol::BTCUSDT, 93842.43, 1.196, 100, 112012.66);
        $long = PositionFactory::long(Symbol::BTCUSDT, 67388.54, 0.446, 100, 0);
        $total = 2289.13194391;
        $totalPositionIM = 1240.6823246;
    # assert
        $expectedAvailable = 687.2721193099999;
        $expectedFree = $total - $totalPositionIM;
        $expectedFreeForLiquidation = 1477.3284044816046;
    # case
        yield sprintf('[with hedge opened] %s UNIFIED: %s `total` | %s `totalPositionIM` => expected %s free | %s available | %s freeForLiquidation', $coin->value, $total, $totalPositionIM, $expectedFree, $expectedAvailable, $expectedFreeForLiquidation) => [
            '$coin' => $coin,
            '$positions' => [$short, $long],
            '$apiResponse' => GetWalletBalanceResponseBuilder::ok()->withUnifiedBalance($coin, $total, $totalPositionIM)->build(),
            'expectedContractBalance' => new ContractBalance($coin, $total, $expectedAvailable, $expectedFree, $expectedFreeForLiquidation),
            '$ticker' => $ticker,
        ];

        // @todo | negative total

//        # with hedge is opened and free balance is negative
//        $support = PositionFactory::short(Symbol::BTCUSDT, 67864.380, 0.410, 100, 0);
//        $main = PositionFactory::long(Symbol::BTCUSDT, 63983.600, 0.486, 100, 46382.900);
//        $expectedFree = -277.7804;
//        yield sprintf('have / %s total and %s available / on %s contract balance (with hedge opened and free balance is negative)', $total, $available, $coin->value) => [
//            '$coin' => $coin,
//            '$positions' => [$main, $support],
//            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
//            'expectedContractBalance' => new ContractBalance($coin, $total, $available, $expectedFree, $expectedFree),
//        ];
//
//        # with equivalent hedge is opened
//        $main = PositionFactory::short(Symbol::BTCUSDT, 63422.060, 0.374, 100, 0);
//        $support = PositionFactory::long(Symbol::BTCUSDT, 60480.590, 0.374, 100, 0);
//        $expectedFree = $available;
//        yield sprintf('have / %s total and %s available / on %s contract balance (with equivalent hedge opened)', $total, $available, $coin->value) => [
//            '$coin' => $coin,
//            '$positions' => [$main, $support],
//            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
//            'expectedContractBalance' => new ContractBalance($coin, $total, $available, $expectedFree, $expectedFree),
//        ];
//
//        // @todo | cover case (based on positionBalance - im) for negative free for long without liquidation (below 0)
//
//        # with almost equivalent hedge is opened
//        $support = new Position(Side::Sell, Symbol::BTCUSDT, 67864.380, 0.410, 30000, 0, 278.244, 182.4028, 100);
//        $main = new Position(Side::Buy, Symbol::BTCUSDT, 63974.990000, 0.422, 30000, 0, 269.9744, 185.6355, 100);
//        $total = 368.0383;
//        $available = 0;
//        $expectedFree = 10.6621; # @todo this is not correct
//        yield sprintf('have / %s total and %s available / on %s contract balance (with almost equivalent hedge opened)', $total, $available, $coin->value) => [
//            '$coin' => $coin,
//            '$positions' => [$main, $support],
//            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
//            'expectedContractBalance' => new ContractBalance($coin, $total, $available, $expectedFree, $expectedFree),
//        ];
//
//        $support = new Position(Side::Sell, Symbol::BTCUSDT, 67864.380, 0.410, 30000, 0, 278.244, 182.4028, 100);
//        $main = new Position(Side::Buy, Symbol::BTCUSDT, 63974.990000, 0.422, 30000, 0, 269.9744, 185.6355, 100);
//        $total = 368.0383;
//        $available = 0.1;
//        $expectedFree = 2.7999;
//        yield sprintf('have / %s total and %s available / on %s contract balance (with almost equivalent hedge opened)', $total, $available, $coin->value) => [
//            '$coin' => $coin,
//            '$positions' => [$main, $support],
//            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
//            'expectedContractBalance' => new ContractBalance($coin, $total, $available, $expectedFree, $expectedFree),
//            '$ticker' => TickerFactory::create(Symbol::BTCUSDT, 63700, 63700, 63750)
//        ];
//
//        # BTC
//        $coin = Coin::BTC;
//        $total = 1.09;
//        $available = 0.11234543;
//        yield sprintf('have %.3f on %s contract balance (without positions opened)', $available, $coin->value) => [
//            '$coin' => $coin,
//            '$positions' => [],
//            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
//            'expectedContractBalance' => new ContractBalance($coin, $total, $available, $total, $total),
//        ];
    }
}
