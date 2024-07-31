<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Dto;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

readonly class ClosedPosition
{
    public function __construct(public Side $side, public Symbol $symbol)
    {
    }
}