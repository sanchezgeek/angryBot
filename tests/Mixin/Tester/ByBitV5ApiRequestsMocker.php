<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Tester;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Request\Account\GetWalletBalanceRequest;
use App\Tests\Mock\Response\ByBitV5Api\Account\AccountBalanceResponseBuilder;

trait ByBitV5ApiRequestsMocker
{
    use ByBitV5ApiTester;

    private function haveSpotBalance(Symbol $symbol, float $amount): void
    {
        $coinAmount = new CoinAmount($coin = $symbol->associatedCoin(), $amount);

        $this->matchGet(
            new GetWalletBalanceRequest(AccountType::SPOT, $coin),
            AccountBalanceResponseBuilder::ok($symbol->associatedCategory())->withCoinBalance(AccountType::SPOT, $coinAmount)->build(),
        );
    }
}