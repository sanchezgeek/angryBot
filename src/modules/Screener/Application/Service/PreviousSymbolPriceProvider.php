<?php

declare(strict_types=1);

namespace App\Screener\Application\Service;

use App\Domain\Candle\Enum\CandleIntervalEnum;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Screener\Domain\Entity\SymbolPriceHistory;
use App\Screener\Domain\Repository\SymbolPriceHistoryRepository;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateInterval;
use DateTimeImmutable;

final readonly class PreviousSymbolPriceProvider
{
    public function __construct(
        private SymbolPriceHistoryRepository $historyRepository,
        private ByBitLinearExchangeService $exchangeService,
    ) {
    }

    public function getPrevPrice(SymbolInterface $symbol, DateTimeImmutable $onDateTime): float
    {
        if (!$historyValue = $this->historyRepository->fundOnMomentOfTime($symbol, $onDateTime)) {
            $candles = $this->exchangeService->getCandles(
                symbol: $symbol,
                interval: CandleIntervalEnum::m15,
                from: $onDateTime,
                to: $onDateTime->add(new DateInterval('PT1M')),
                limit: 1
            );
            $openPrice = $candles[0]['open'];

            $this->historyRepository->save(
                new SymbolPriceHistory($symbol, $openPrice, $onDateTime)
            );

            return $openPrice;
        }

        return $historyValue->lastPrice;
    }
}
