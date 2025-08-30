<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Symbol\SymbolInterface;
use Stringable;

final readonly class OpenPositionEntryDto implements Stringable
{
    public function __construct(
        public SymbolInterface $symbol,
        public Side $positionSide,
        public Percent $percentOfDepositToRisk,
        public bool $withStops,
        public bool $closeAndReopenCurrentPosition,
        public bool $removeExistedStops = false,
        public bool $dryRun = false,
        public bool $outputEnabled = false,
        public ?OrdersGridDefinitionCollection $buyGridsDefinition = null,
        public ?OrdersGridDefinitionCollection $stopsGridsDefinition = null,
    ) {
    }

    public function __toString(): string
    {
        return json_encode($this);
    }
}
