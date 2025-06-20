<?php

declare(strict_types=1);

namespace App\Screener\Application\Service\Exception;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;

final class CandlesHistoryNotFound extends \Exception
{
    public function __construct(
        public readonly SymbolInterface $symbol,
        public readonly CandleIntervalEnum $candleInterval,
        public readonly DateTimeImmutable $fromDateTime,
    ) {
        parent::__construct(
            sprintf('Cannot find history for %s (%s) from %s', $symbol->name(), $candleInterval->value, $this->fromDateTime->format('Y:m:d H:i:s'))
        );
    }
}
