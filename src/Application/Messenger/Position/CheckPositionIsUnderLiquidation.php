<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\Symfony\Messenger\Async\Debug\MessageWithDispatchingTimeTrait;

/**
 * @codeCoverageIgnore
 */
final class CheckPositionIsUnderLiquidation
{
    use MessageWithDispatchingTimeTrait;

    public function __construct(public readonly Symbol $symbol) {}
}
