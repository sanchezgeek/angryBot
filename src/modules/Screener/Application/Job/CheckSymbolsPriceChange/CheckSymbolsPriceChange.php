<?php

declare(strict_types=1);

namespace App\Screener\Application\Job\CheckSymbolsPriceChange;

use App\Domain\Coin\Coin;
use DateInterval;
use LogicException;

/**
 * @codeCoverageIgnore
 */
final readonly class CheckSymbolsPriceChange
{
    public function __construct(public Coin $settleCoin, public int $daysDelta = 0)
    {
        if ($this->daysDelta < 0) {
            throw new LogicException(sprintf('Days delta cannot be less than 0 (%s provided)', $this->daysDelta));
        }
    }
}
