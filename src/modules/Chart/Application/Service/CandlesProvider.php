<?php

declare(strict_types=1);

namespace App\Chart\Application\Service;

use App\Domain\Trading\Enum\TimeFrame;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\TechnicalAnalysis\Domain\Dto\CandleDto;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateTimeImmutable;
use LogicException;

final readonly class CandlesProvider
{
    public function __construct(private ByBitLinearExchangeService $exchangeService)
    {
    }

    /**
     * @return CandleDto[]
     */
    public function getCandles(SymbolInterface $symbol, TimeFrame $interval, DateTimeImmutable $from, ?DateTimeImmutable $to = null, ?int $limit = null): array
    {
        $data = $this->exchangeService->getCandles($symbol, $interval, $from, $to, $limit);
        if ($limit && count($data) > $limit) {
            throw new LogicException(sprintf('Got %d candles insteadof requested %d', count($data), $limit));
        }

        return array_map(static fn(array $item) => new CandleDto($interval, $item['time'], $item['open'], $item['high'], $item['low'], $item['close']), $data);
    }
}
