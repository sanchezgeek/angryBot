<?php

declare(strict_types=1);

namespace App\Application\Messenger\Market;

use App\Bot\Domain\ValueObject\Symbol;

/**
 * @codeCoverageIgnore
 */
final class TransferFundingFees
{
    public function __construct(public Symbol $symbol)
    {
    }
}
