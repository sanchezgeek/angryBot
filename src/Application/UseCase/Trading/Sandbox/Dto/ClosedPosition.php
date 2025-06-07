<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

readonly class ClosedPosition
{
    public function __construct(public Side $side, public SymbolInterface $symbol)
    {
    }
}
