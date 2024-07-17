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
        # USDT
        $coin = Coin::USDT;
        $total = 600.9;
        $available = 105.1;

        yield sprintf('have %.3f on %s contract balance (without positions opened)', $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $total),
        ];

        $main = PositionFactory::short(Symbol::BTCUSDT, 63422.060, 0.374, 100, 64711.64);
        $expectedFree = 363.7036;
        yield sprintf('have %.3f on %s contract balance (with short opened)', $available, $coin->value) => [
            '$coin' => $coin,
            '$positions' => [$main],
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available, $expectedFree),
        ];

        $main = PositionFactory::short(Symbol::BTCUSDT, 63422.060, 0.374, 100, 76433.16);
        $support = PositionFactory::long(Symbol::BTCUSDT, 60480.590, 0.284, 100, 0);
        $expectedFree = 307.0816;
        yield sprintf('have %.3f on %s contract balance (with hedge opened)', $available, $coin->value) => [
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
