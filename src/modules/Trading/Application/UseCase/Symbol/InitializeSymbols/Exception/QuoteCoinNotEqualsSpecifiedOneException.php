<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\Symbol\InitializeSymbols\Exception;

use App\Domain\Coin\Coin;

final class QuoteCoinNotEqualsSpecifiedOneException extends \Exception
{
    public function __construct(string $assetQuoteCoin, Coin $specifiedCoin)
    {
        parent::__construct(
            sprintf('QuoteCoin ("%s") !== specified coin ("%s")', $assetQuoteCoin, $specifiedCoin->value)
        );
    }
}
