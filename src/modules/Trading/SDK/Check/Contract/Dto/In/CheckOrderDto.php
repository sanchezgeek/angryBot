<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Contract\Dto\In;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

interface CheckOrderDto
{
    public function symbol(): Symbol;
    public function positionSide(): Side;
    public function priceValueWillBeingUsedAtExecution(): float;
    public function orderQty(): float;
    public function orderIdentifier(): ?string;
}
