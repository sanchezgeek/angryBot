<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\Push\MainPositionsStops;

use App\Bot\Domain\ValueObject\Symbol;

final class MainStopsPushLastSortStorage
{
    private ?array $lastSort = null;

    public function setLastSort(array $symbols): void
    {
        $this->lastSort = $symbols;
    }

    /**
     * @return Symbol[]|null
     */
    public function getLastSort(): ?array
    {
        return $this->lastSort;
    }
}
