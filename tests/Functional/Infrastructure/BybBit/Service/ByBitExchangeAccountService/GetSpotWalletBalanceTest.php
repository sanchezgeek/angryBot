<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitExchangeAccountService;

use App\Bot\Application\Service\Exchange\Dto\SpotBalance;
use App\Domain\Coin\Coin;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Asset\Balance\GetAllCoinsBalanceRequest;
use App\Tests\Mock\Response\ByBitV5Api\Account\AllCoinsBalanceResponseBuilder;
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
        SpotBalance $expectedSpotBalance
    ): void {
        $this->matchGet(new GetAllCoinsBalanceRequest(AccountType::FUNDING, $coin), $apiResponse);

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
        yield sprintf('have %.3f on %s fund', $amount, $coin->value) => [
            '$coin' => $coin,
            '$apiResponse' => AllCoinsBalanceResponseBuilder::ok()->withAvailableFundBalance($coinAmount)->build(),
            'expectedSpotBalance' => new SpotBalance($coin, $amount, $amount),
        ];

        $coin = Coin::BTC;
        $amount = 0.11234543;
        $coinAmount = new CoinAmount($coin, $amount);
        yield sprintf('have %.8f on %s fund', $amount, $coin->value) => [
            '$coin' => $coin,
            '$apiResponse' => AllCoinsBalanceResponseBuilder::ok()->withAvailableFundBalance($coinAmount)->build(),
            'expectedSpotBalance' => new SpotBalance($coin, $amount, $amount),
        ];
    }
}
