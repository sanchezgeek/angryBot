<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\Orders;

use App\Bot\Application\Service\Orders\Dto\CreatedIncGridInfo;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;

interface StopServiceInterface
{
    public function create(
        SymbolInterface $symbol,
        Side $positionSide,
        SymbolPrice|float $price,
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
