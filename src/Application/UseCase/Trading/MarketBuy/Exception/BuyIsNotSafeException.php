<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\MarketBuy\Exception;

use Exception;

final class BuyIsNotSafeException extends Exception
{
    public static function liquidationTooNear(float $withDistance, float $minApplicableDistance): self
    {
        return new self(sprintf('liquidationPrice is too near [distance = %s vs min.=%s]', $withDistance, $minApplicableDistance));
    }
}
