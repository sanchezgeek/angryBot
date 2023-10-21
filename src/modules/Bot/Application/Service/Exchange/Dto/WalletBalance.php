<?php

declare(strict_types=1);


namespace App\Bot\Application\Service\Exchange\Dto;

use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\API\V5\Enum\Account\Coin;

/** Или это уже Domain? */
final readonly class WalletBalance
{
    public function __construct(
        public AccountType $accountType,
        public Coin        $assetCoin,
        public float       $availableBalance
    ) {
    }
}
