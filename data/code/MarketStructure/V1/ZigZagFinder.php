<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure\V1;

use App\TechnicalAnalysis\Domain\Dto\CandleDto;

final class ZigZagFinder
{
    public const PEAK = 'peak';
    public const TROUGH = 'trough';

    private array $pivots = [];
    private ?int $lastPivotIndex = null;
    private ?float $lastPivotPrice = null;
    private ?string $lastPivotType = null;
    private ?float $currentExtremePrice = null;
    private ?int $currentExtremeIndex = null;
    private ?string $currentExtremeType = null;

    public function find(array $candles): array
    {
        if (count($candles) < 2) return [];

        $this->initFirstPivot($candles[0]);

        for ($i = 1; $i < count($candles); $i++) {
            $candle = $candles[$i];

            // Обновляем текущий экстремум в направлении тренда
            $this->updateCurrentExtreme($candle, $i);

            // Проверяем условия для фиксации нового пивота
            $this->checkForPivot($candle, $i);
        }

        // Фиксируем последний экстремум как пивот
        $this->addCurrentExtremeAsPivot();
        return $this->pivots;
    }

    private function initFirstPivot(CandleDto $firstCandle): void
    {
        $this->lastPivotIndex = 0;
        $this->lastPivotPrice = $firstCandle->high;
        $this->lastPivotType = self::PEAK;
        $this->currentExtremePrice = $firstCandle->high;
        $this->currentExtremeIndex = 0;
        $this->currentExtremeType = self::PEAK;
    }

    private function updateCurrentExtreme(CandleDto $candle, int $index): void
    {
        if ($this->lastPivotType === self::PEAK) {
            if ($candle->low < $this->currentExtremePrice) {
                $this->currentExtremePrice = $candle->low;
                $this->currentExtremeIndex = $index;
                $this->currentExtremeType = self::TROUGH;
            }
        } else {
            if ($candle->high > $this->currentExtremePrice) {
                $this->currentExtremePrice = $candle->high;
                $this->currentExtremeIndex = $index;
                $this->currentExtremeType = self::PEAK;
            }
        }
    }

    private function checkForPivot(CandleDto $candle, int $index): void
    {
        if ($this->lastPivotType === self::PEAK) {
            // Для нисходящего движения: фиксация впадины при пробитии предыдущего максимума
            if ($candle->high > $this->lastPivotPrice) {
                $this->addCurrentExtremeAsPivot();
                $this->resetCurrentExtreme($candle, $index, self::PEAK);
            }
        } else {
            // Для восходящего движения: фиксация вершины при пробитии предыдущего минимума
            if ($candle->low < $this->lastPivotPrice) {
                $this->addCurrentExtremeAsPivot();
                $this->resetCurrentExtreme($candle, $index, self::TROUGH);
            }
        }
    }

    private function addCurrentExtremeAsPivot(): void
    {
        if ($this->currentExtremeIndex === null) return;

        $this->pivots[] = new ZigZagPoint(
            $this->currentExtremeIndex,
            $this->currentExtremePrice,
            $this->currentExtremeType
        );

        $this->lastPivotIndex = $this->currentExtremeIndex;
        $this->lastPivotPrice = $this->currentExtremePrice;
        $this->lastPivotType = $this->currentExtremeType;
    }

    private function resetCurrentExtreme(CandleDto $candle, int $index, string $type): void
    {
        $this->currentExtremePrice = ($type === self::PEAK)
            ? $candle->high
            : $candle->low;

        $this->currentExtremeIndex = $index;
        $this->currentExtremeType = $type;
    }
}
