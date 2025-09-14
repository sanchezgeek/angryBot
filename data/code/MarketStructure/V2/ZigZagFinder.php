<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure\V2;

use App\TechnicalAnalysis\Domain\Dto\CandleDto;

final class ZigZagFinder
{
    private array $pivots = [];
    /** @var CandleDto[] */
    private array $candles = [];

    public function find(array $candles): array
    {
        $this->candles = $candles;
        $this->pivots = [];
        $extremes = $this->findPotentialExtremes();
        $this->filterExtremes($extremes);
        return $this->pivots;
    }

    private function findPotentialExtremes(): array
    {
        $extremes = [];
        $count = count($this->candles);

        for ($i = 1; $i < $count - 1; $i++) {
            $prev = $this->candles[$i-1];
            $curr = $this->candles[$i];
            $next = $this->candles[$i+1];

            // Локальный максимум
            if ($curr->getHigh() >= $prev->getHigh() &&
                $curr->getHigh() >= $next->getHigh()) {
                $extremes[] = [
                    'index' => $i,
                    'price' => $curr->getHigh(),
                    'type' => ZigZagPoint::PEAK,
                    'datetime' => $curr->getUtcDatetime(),
                    'time' => $curr->time,
                ];
            }

            // Локальный минимум
            if ($curr->getLow() <= $prev->getLow() &&
                $curr->getLow() <= $next->getLow()) {
                $extremes[] = [
                    'index' => $i,
                    'price' => $curr->getLow(),
                    'type' => ZigZagPoint::TROUGH,
                    'datetime' => $curr->getUtcDatetime(),
                    'time' => $curr->time,
                ];
            }
        }

        // Сортируем экстремумы по индексу
        usort($extremes, fn($a, $b) => $a['index'] <=> $b['index']);
        return $extremes;
    }

    private function filterExtremes(array $extremes): void
    {
        $count = count($extremes);
        $confirmed = [];

        for ($i = 0; $i < $count; $i++) {
            $current = $extremes[$i];
            $isConfirmed = false;

            if ($current['type'] === ZigZagPoint::PEAK) {
                // Для пика: ищем последующее снижение и затем новый пик выше
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($extremes[$j]['type'] === ZigZagPoint::TROUGH) {
                        // Нашли впадину после пика
                        for ($k = $j + 1; $k < $count; $k++) {
                            if ($extremes[$k]['type'] === ZigZagPoint::PEAK &&
                                $extremes[$k]['price'] > $current['price']) {
                                $isConfirmed = true;
                                break 2;
                            }
                        }
                    }
                }
            } else {
                // Для впадины: ищем последующий рост и затем новую впадину ниже
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($extremes[$j]['type'] === ZigZagPoint::PEAK) {
                        // Нашли пик после впадины
                        for ($k = $j + 1; $k < $count; $k++) {
                            if ($extremes[$k]['type'] === ZigZagPoint::TROUGH &&
                                $extremes[$k]['price'] < $current['price']) {
                                $isConfirmed = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            if ($isConfirmed) {
                $confirmed[] = $current;
            }
        }

        // Преобразуем в объекты ZigZagPoint
        foreach ($confirmed as $item) {
            $this->pivots[] = new ZigZagPoint(
                $item['index'],
                $item['price'],
                $item['type'],
                $item['time']
            );
        }
    }
}
