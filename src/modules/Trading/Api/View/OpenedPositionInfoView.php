<?php

declare(strict_types=1);

namespace App\Trading\Api\View;

use App\Bot\Domain\Position;
use JsonSerializable;

final readonly class OpenedPositionInfoView implements JsonSerializable
{
    public function __construct(
        private Position $position
    ) {
    }

    public function jsonSerialize(): array
    {
        $liquidationPrice = $this->position->liquidationPrice()->value();

        return [
            'symbol' => $this->position->symbol->name(),
            'side' => $this->position->side->value,
            'entryPrice' => $this->position->entryPrice()->value(),
            'liquidationPrice' => $liquidationPrice !== 0.00 ? $liquidationPrice : null,
        ];
    }
}
