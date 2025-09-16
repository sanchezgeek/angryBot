<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Dto;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Application\AutoOpen\Reason\ReasonForOpenPositionInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Stringable;

/**
 * @todo move ticker here?
 */
final readonly class InitialPositionAutoOpenClaim implements Stringable
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public ReasonForOpenPositionInterface $reason,
    ) {
    }

    public function __toString(): string
    {
        return json_encode($this);
    }
}
