<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Exchange\Exception;

use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Enum\Account\Coin;
use Exception;

use function sprintf;

final class AccountCoinDataNotFound extends Exception
{
    public function __construct(string $exchangeName, AccountType $accountType, Coin $coin)
    {
        parent::__construct(
            sprintf('[%s] %s %s coin data not found', $exchangeName, $accountType->value, $coin->value)
        );
    }
}
