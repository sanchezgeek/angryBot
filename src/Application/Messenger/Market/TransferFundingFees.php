<?php

declare(strict_types=1);

namespace App\Application\Messenger\Market;

use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @codeCoverageIgnore
 */
final class TransferFundingFees
{
    public function __construct(public SymbolInterface $symbol)
    {
    }
}
