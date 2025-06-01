<?php

declare(strict_types=1);

namespace App\Chart\Application;

use App\Bot\Domain\ValueObject\Symbol;
use App\Chart\Api\View\CandleView;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use DateTimeImmutable;

final readonly class CandlesProvider
{
    public function __construct(private ByBitLinearExchangeService $exchangeService)
    {
    }

    /**
     * @return CandleView[]
     */
    public function getCandles(Symbol $symbol, DateTimeImmutable $from, DateTimeImmutable $to, int $interval = 15, ?int $limit = null): array
    {
        $data = $this->exchangeService->getKlines($symbol, $from, $to, $interval, $limit);

        return array_map(static fn(array $item) => new CandleView($item['time'], $item['open'], $item['high'], $item['low'], $item['close']), $data);
    }
}
