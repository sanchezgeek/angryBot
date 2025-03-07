<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Orders;

use App\Bot\Application\Service\Orders\Dto\CreatedIncGridInfo;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;

interface StopServiceInterface
{
    public function create(
        Symbol $symbol,
        Side $positionSide,
        Price|float $price,
        float $volume,
        ?float $triggerDelta = null,
        array $context = [],
    ): int;

    public function createIncrementalToPosition(
        Position $position,
        float $volume,
        float $fromPrice,
        float $toPrice,
        array $context = []
    ): CreatedIncGridInfo;
}
