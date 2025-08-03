<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\PushStopsToTexchange;

use App\Stop\Application\UseCase\PushStopsToTexchange\Dto\PushStopResult;
use App\Stop\Application\UseCase\PushStopsToTexchange\Dto\PusStopEntry;

interface PushStopStrategyInterface
{
    public function supports(PusStopEntry $entryDto): bool;
    public function push(PusStopEntry $entryDto): PushStopResult;
}
