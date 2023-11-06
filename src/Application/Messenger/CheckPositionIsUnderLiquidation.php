<?php

declare(strict_types=1);

namespace App\Application\Messenger;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

/**
 * @codeCoverageIgnore
 */
final readonly class CheckPositionIsUnderLiquidation
{
    public function __construct(public Symbol $symbol, public Side $side)
    {
    }
}
