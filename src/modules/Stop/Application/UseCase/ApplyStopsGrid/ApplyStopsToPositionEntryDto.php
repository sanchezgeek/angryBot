<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\ApplyStopsGrid;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class ApplyStopsToPositionEntryDto
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public float $totalSize,
        public OrdersGridDefinitionCollection $stopsGridsDefinition,
        public array $additionalContext = [],
        public bool $dry = false,
    ) {
    }
}
