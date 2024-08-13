<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\CacheDecorated\Dto;

use App\Bot\Domain\Ticker;

readonly class CachedTickerDto
{
    public function __construct(public Ticker $ticker, public string $updatedByAccName)
    {
    }
}
