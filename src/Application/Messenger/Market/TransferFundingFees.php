<?php

declare(strict_types=1);

namespace App\Application\Messenger\Market;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

/**
 * @codeCoverageIgnore
 */
final class TransferFundingFees
{
    public function __construct(public SymbolInterface $symbol)
    {
    }
}
