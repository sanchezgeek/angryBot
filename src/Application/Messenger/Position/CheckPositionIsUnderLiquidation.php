<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position;

use App\Application\Messenger\TimeStampedAsyncMessageTrait;
use App\Bot\Domain\ValueObject\Symbol;

/**
 * @codeCoverageIgnore
 */
final class CheckPositionIsUnderLiquidation
{
    use TimeStampedAsyncMessageTrait;

    public function __construct(public readonly Symbol $symbol) {}
}
