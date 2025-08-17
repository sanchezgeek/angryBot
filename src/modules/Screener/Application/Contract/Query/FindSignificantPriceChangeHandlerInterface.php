<?php

declare(strict_types=1);

namespace App\Screener\Application\Contract\Query;

interface FindSignificantPriceChangeHandlerInterface
{
    /**
     * @return FindSignificantPriceChangeResponse[]
     */
    public function handle(FindSignificantPriceChange $message): array;
}
