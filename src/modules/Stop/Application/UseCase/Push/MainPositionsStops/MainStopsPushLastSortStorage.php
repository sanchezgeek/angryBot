<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\Push\MainPositionsStops;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;

final class MainStopsPushLastSortStorage
{
    private ?array $lastSort = null;

    public function setLastSort(array $symbols): void
    {
        $this->lastSort = $symbols;
    }

    /**
     * @return SymbolInterface[]|null
     */
    public function getLastSort(): ?array
    {
        return $this->lastSort;
    }
}
