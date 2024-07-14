<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitExchangeAccountService;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBitV5Api\Account\AccountBalanceResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService::getSpotWalletBalance
 */
final class GetSpotWalletBalanceTest extends ByBitExchangeAccountServiceTestAbstract
{
    /**
     * @dataProvider getSpotWalletBalanceSuccessTestCases
     */
    public function testCanGetSpotWalletBalance(
        Coin $coin,
        MockResponse $apiResponse,
        WalletBalance $expectedSpotBalance
    ): void {
        $this->matchGet(new GetWalletBalanceRequest(AccountType::SPOT, $coin), $apiResponse);

        // Act
        $spotBalance = $this->service->getSpotWalletBalance($coin);

        // Assert
        self::assertEquals($expectedSpotBalance, $spotBalance);
    }

    private function getSpotWalletBalanceSuccessTestCases(): iterable
    {
        $coin = Coin::USDT;
        $amount = 105.1;
        $coinAmount = new CoinAmount($coin, $amount);
        yield sprintf('have %.3f on %s spot', $amount, $coin->value) => [
            '$coin' => $coin,
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withAvailableSpotBalance($coinAmount)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::SPOT, $coin, $amount, $amount),
        ];

        $coin = Coin::BTC;
        $amount = 0.11234543;
        $coinAmount = new CoinAmount($coin, $amount);
        yield sprintf('have %.8f on %s spot', $amount, $coin->value) => [
            '$coin' => $coin,
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withAvailableSpotBalance($coinAmount)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::SPOT, $coin, $amount, $amount),
        ];
    }
}
