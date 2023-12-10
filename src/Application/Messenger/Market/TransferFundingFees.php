<?php

declare(strict_types=1);

namespace App\Application\Messenger\Market;

use App\Application\Messenger\TimeStampedAsyncMessageTrait;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

/**
 * @codeCoverageIgnore
 */
final class TransferFundingFees
{
    use TimeStampedAsyncMessageTrait;

    public function __construct(public Symbol $symbol, public Side $side)
    {
    }
}
