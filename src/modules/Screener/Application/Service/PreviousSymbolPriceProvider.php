<?php

declare(strict_types=1);

namespace App\Screener\Application\Service;

use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Screener\Domain\Entity\SymbolPriceHistory;
use App\Screener\Domain\Repository\SymbolPriceHistoryRepository;
use DateInterval;
use DateTimeImmutable;

final readonly class PreviousSymbolPriceProvider
{
    public function __construct(
        private SymbolPriceHistoryRepository $historyRepository,
        private ByBitLinearExchangeService $exchangeService,
    ) {
    }

    public function getPrevPrice(string $symbolRaw, DateTimeImmutable $onDateTime): float
    {
        if (!$historyValue = $this->historyRepository->fundOnMomentOfTime($symbolRaw, $onDateTime)) {
            $klines = $this->exchangeService->getKlines(
                symbol: $symbolRaw,
                from: $onDateTime,
                to: $onDateTime->add(new DateInterval('PT1M')),
                limit: 1
            );
            $openPrice = $klines[0]['open'];

            $this->historyRepository->save(
                new SymbolPriceHistory($symbolRaw, $openPrice, $onDateTime)
            );

            return $openPrice;
        }

        return $historyValue->lastPrice;
    }
}
