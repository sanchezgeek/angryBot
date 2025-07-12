<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\MoveStops;

interface MoveStopsToBreakevenHandlerInterface
{
    public function handle(MoveStopsToBreakevenEntryDto $entryDto);
}
