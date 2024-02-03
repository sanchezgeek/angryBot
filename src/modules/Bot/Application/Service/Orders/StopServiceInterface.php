<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Orders;

use App\Bot\Application\Service\Orders\Dto\CreatedIncGridInfo;
use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;

interface StopServiceInterface
{
    public function create(Side $positionSide, float $price, float $volume, float $triggerDelta, array $context = []): int;
    public function createIncrementalToPosition(
        Position $position,
        float $volume,
        float $fromPrice,
        float $toPrice,
        array $context = []
    ): CreatedIncGridInfo;
}
