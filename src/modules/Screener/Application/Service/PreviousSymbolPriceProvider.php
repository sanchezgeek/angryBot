<?php

declare(strict_types=1);

namespace App\Screener\Application\Service;

use App\Domain\Trading\Enum\TimeFrame;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Screener\Application\Service\Exception\CandlesHistoryNotFound;
use App\Screener\Domain\Entity\SymbolPriceHistory;
use App\Screener\Domain\Repository\SymbolPriceHistoryRepository;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;

final readonly class PreviousSymbolPriceProvider
{
    public function __construct(
        private SymbolPriceHistoryRepository $historyRepository,
        private ByBitLinearExchangeService $exchangeService,
    ) {
    }

    /**
     * @throws CandlesHistoryNotFound
     */
    public function getPrevPrice(SymbolInterface $symbol, DateTimeImmutable $onDateTime): float
    {
        if (!$historyValue = $this->historyRepository->fundOnMomentOfTime($symbol, $onDateTime)) {
            $candles = $this->exchangeService->getCandles(
                symbol: $symbol,
                interval: TimeFrame::m15,
                from: $onDateTime,
                limit: 1
            );

            if (!$candles) {
                throw new CandlesHistoryNotFound($symbol, TimeFrame::m15, $onDateTime);
            }

            $openPrice = $candles[0]['open'];

            $this->historyRepository->save(
                new SymbolPriceHistory($symbol, $openPrice, $onDateTime)
            );

            return $openPrice;
        }

        return $historyValue->lastPrice;
    }
}
