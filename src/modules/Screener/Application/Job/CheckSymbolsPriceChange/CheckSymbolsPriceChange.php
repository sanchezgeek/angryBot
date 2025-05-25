<?php

declare(strict_types=1);

namespace App\Screener\Application\Job\CheckSymbolsPriceChange;

use App\Domain\Coin\Coin;
use DateInterval;

/**
 * @codeCoverageIgnore
 */
final readonly class CheckSymbolsPriceChange
{
    public function __construct(public Coin $settleCoin, public ?DateInterval $timeIntervalWithPrev = null)
    {
    }
}
