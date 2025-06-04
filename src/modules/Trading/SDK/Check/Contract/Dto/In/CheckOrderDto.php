<?php

declare(strict_types=1);

namespace App\Trading\SDK\Check\Contract\Dto\In;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

interface CheckOrderDto
{
    public function symbol(): SymbolInterface;
    public function positionSide(): Side;
    public function priceValueWillBeingUsedAtExecution(): float;
    public function orderQty(): float;
    public function orderIdentifier(): ?string;
}
