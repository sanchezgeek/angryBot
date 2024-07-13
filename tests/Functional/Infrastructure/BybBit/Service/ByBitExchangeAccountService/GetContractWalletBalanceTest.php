<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitExchangeAccountService;

use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use App\Tests\Mock\Response\ByBitV5Api\Account\AccountBalanceResponseBuilder;
use Symfony\Component\HttpClient\Response\MockResponse;

use function sprintf;

/**
 * @covers \App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService::getContractWalletBalance
 */
final class GetContractWalletBalanceTest extends ByBitExchangeAccountServiceTestAbstract
{
    use ByBitV5ApiTester;

    private const REQUEST_URL = GetWalletBalanceRequest::URL;
    private const CALLED_METHOD = 'ByBitExchangeAccountService::getSpotWalletBalance';

    /**
     * @dataProvider getContractWalletBalanceSuccessTestCases
     */
    public function testCanGetContractWalletBalance(
        Coin $coin,
        MockResponse $apiResponse,
        WalletBalance $expectedSpotBalance
    ): void {
        $this->matchGet(new GetWalletBalanceRequest(AccountType::CONTRACT, $coin), $apiResponse);

        // Act
        $spotBalance = $this->service->getContractWalletBalance($coin);

        // Assert
        self::assertEquals($expectedSpotBalance, $spotBalance);
    }

    private function getContractWalletBalanceSuccessTestCases(): iterable
    {
        $coin = Coin::USDT;
        $total = 200.9;
        $available = 105.1;
        yield sprintf('have %.3f on %s contract balance', $available, $coin->value) => [
            '$coin' => $coin,
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available),
        ];

        $coin = Coin::BTC;
        $total = 1.09;
        $available = 0.11234543;
        yield sprintf('have %.3f on %s contract balance', $available, $coin->value) => [
            '$coin' => $coin,
            '$apiResponse' => AccountBalanceResponseBuilder::ok()->withContractBalance($coin, $total, $available)->build(),
            'expectedSpotBalance' => new WalletBalance(AccountType::CONTRACT, $coin, $total, $available),
        ];
    }
}
