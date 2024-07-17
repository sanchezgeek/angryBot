<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitExchangeAccountService;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Tests\Factory\PositionFactory;
use App\Tests\Mock\Response\ByBitV5Api\Account\AccountBalanceResponseBuilder;
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
        WalletBalance $expectedSpotBalance
    ): void {
        $this->matchGet(new GetWalletBalanceRequest(AccountType::CONTRACT, $coin), $apiResponse);
        $this->havePosition(Symbol::BTCUSDT, ...$positions);

        // Act
        $spotBalance = $this->service->getContractWalletBalance($coin);

        // Assert
        self::assertEquals($expectedSpotBalance, $spotBalance);
    }

    private function getContractWalletBalanceSuccessTestCases(): iterable
    {
        ### USDT
        $coin = Coin::USDT;
        $total = 600.9;
        $available = 105.1;

        # without positions opened
        yield sprintf('have / %s total and %s available / on %s contract balance (without positions opened)', $total, $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $total),
        ];

        # with only short is opened
        $main = PositionFactory::short(Symbol::BTCUSDT, 63422.060, 0.374, 100, 64711.64);
        $expectedFree = 363.7036;
        yield sprintf('have / %s total and %s available / on %s contract balance (with short opened)', $total, $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [$main],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $expectedFree),
        ];

        # with hedge is opened and there is some free balance
        $main = PositionFactory::short(Symbol::BTCUSDT, 63422.060, 0.374, 100, 76433.16);
        $support = PositionFactory::long(Symbol::BTCUSDT, 60480.590, 0.284, 100, 0);
        $expectedFree = 307.0816;
        yield sprintf('have / %s total and %s available / on %s contract balance (with hedge opened and there is some free balance)', $total, $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [$main, $support],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $expectedFree),
        ];

        # with hedge is opened and free balance is negative
        $support = PositionFactory::short(Symbol::BTCUSDT, 67864.380, 0.410, 100, 0);
        $main = PositionFactory::long(Symbol::BTCUSDT, 63983.600, 0.486, 100, 46382.900);
        $expectedFree = -277.7804;
        yield sprintf('have / %s total and %s available / on %s contract balance (with hedge opened and free balance is negative)', $total, $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [$main, $support],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $expectedFree),
        ];

        # with equivalent hedge is opened
        $main = PositionFactory::short(Symbol::BTCUSDT, 63422.060, 0.374, 100, 0);
        $support = PositionFactory::long(Symbol::BTCUSDT, 60480.590, 0.374, 100, 0);
        $expectedFree = $available;
        yield sprintf('have / %s total and %s available / on %s contract balance (with equivalent hedge opened)', $total, $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [$main, $support],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $expectedFree),
        ];

        # with almost equivalent hedge is opened
        $support = PositionFactory::short(Symbol::BTCUSDT, 63251.150, 0.234, 100, 0);
        $main = PositionFactory::long(Symbol::BTCUSDT, 60531.140, 0.235, 100, 100);
        $total = 190.6271;
        $available = 0.2642;
        $expectedFree = $available;
        yield sprintf('have / %s total and %s available / on %s contract balance (with almost equivalent hedge opened)', $total, $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [$main, $support],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $expectedFree),
        ];

        # BTC
        $coin = Coin::BTC;
        $total = 1.09;
        $available = 0.11234543;
        yield sprintf('have %.3f on %s contract balance (without positions opened)', $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $total),
        ];
    }
}
