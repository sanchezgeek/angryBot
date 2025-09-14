<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure;

use App\TechnicalAnalysis\Application\MarketStructure\Dto\ZigZagPoint;
use App\TechnicalAnalysis\Domain\Dto\CandleDto;

final class ZigZagFinder
{
    public const PEAK = 'peak';
    public const TROUGH = 'trough';

    private array $pivots = [];
    /** @var CandleDto[] */
    private array $candles = [];
    private ?int $lastPivotIndex = null;
    private ?float $lastPivotPrice = null;
    private ?string $lastPivotType = null;
    private ?float $currentExtremePrice = null;
    private ?int $currentExtremeIndex = null;

    public function find(array $candles): array
    {
        if (count($candles) < 3) return [];

        $this->candles = $candles;
        $this->resetState();

        for ($i = 1; $i < count($this->candles) - 1; $i++) {
            $this->processCandle($i);
        }

        $this->addFinalPoint();
        return $this->pivots;
    }

    private function resetState(): void
    {
        $this->pivots = [];
        $this->lastPivotIndex = null;
        $this->lastPivotPrice = null;
        $this->lastPivotType = null;
        $this->currentExtremePrice = null;
        $this->currentExtremeIndex = null;

        // Инициализация первыми двумя свечами
        $first = $this->candles[0];
        $second = $this->candles[1];

        if ($first->getHigh() > $second->getHigh()) {
            $this->lastPivotType = self::PEAK;
            $this->lastPivotPrice = $first->getHigh();
            $this->lastPivotIndex = 0;
        } elseif ($first->getLow() < $second->getLow()) {
            $this->lastPivotType = self::TROUGH;
            $this->lastPivotPrice = $first->getLow();
            $this->lastPivotIndex = 0;
        }
    }

    private function processCandle(int $index): void
    {
        $candle = $this->candles[$index];
        $prevCandle = $this->candles[$index-1];
        $nextCandle = $this->candles[$index+1];

        // Определение локальных экстремумов
        $isPeak = $candle->getHigh() >= $prevCandle->getHigh() &&
            $candle->getHigh() >= $nextCandle->getHigh();

        $isTrough = $candle->getLow() <= $prevCandle->getLow() &&
            $candle->getLow() <= $nextCandle->getLow();

        if (!$isPeak && !$isTrough) {
            return;
        }

        if ($this->lastPivotType === null) {
            $this->initFirstPivot($index, $isPeak, $candle);
            return;
        }

        if ($isPeak) {
            $this->handlePeak($index, $candle);
        } elseif ($isTrough) {
            $this->handleTrough($index, $candle);
        }
    }

    private function initFirstPivot(int $index, bool $isPeak, CandleDto $candle): void
    {
        if ($isPeak) {
            $this->lastPivotType = self::PEAK;
            $this->lastPivotPrice = $candle->getHigh();
            $this->lastPivotIndex = $index;
        } else {
            $this->lastPivotType = self::TROUGH;
            $this->lastPivotPrice = $candle->getLow();
            $this->lastPivotIndex = $index;
        }
    }

    private function handlePeak(int $index, CandleDto $candle): void
    {
        $price = $candle->getHigh();

        if ($this->lastPivotType === self::TROUGH) {
            // Проверка на смену направления вверх
            if ($price > $this->lastPivotPrice) {
                $this->addPivot($index, $price, self::PEAK);
            }
        } elseif ($this->lastPivotType === self::PEAK) {
            // Обновление текущего пика, если он выше предыдущего
            if ($price > $this->lastPivotPrice) {
                $this->updateLastPivot($index, $price, self::PEAK);
            }
        }
    }

    private function handleTrough(int $index, CandleDto $candle): void
    {
        $price = $candle->getLow();

        if ($this->lastPivotType === self::PEAK) {
            // Проверка на смену направления вниз
            if ($price < $this->lastPivotPrice) {
                $this->addPivot($index, $price, self::TROUGH);
            }
        } elseif ($this->lastPivotType === self::TROUGH) {
            // Обновление текущей впадины, если она ниже предыдущей
            if ($price < $this->lastPivotPrice) {
                $this->updateLastPivot($index, $price, self::TROUGH);
            }
        }
    }

    private function addPivot(int $index, float $price, string $type): void
    {
        $this->pivots[] = new ZigZagPoint(
            $index,
            $price,
            $type,
//            $this->candles[$index]->getUtcDatetime()
            $this->candles[$index]->time
        );

        $this->lastPivotIndex = $index;
        $this->lastPivotPrice = $price;
        $this->lastPivotType = $type;
    }

    private function updateLastPivot(int $index, float $price, string $type): void
    {
        // Обновляем последнюю точку
        $this->lastPivotIndex = $index;
        $this->lastPivotPrice = $price;
        $this->lastPivotType = $type;

        // Обновляем последнюю точку в массиве
        if (!empty($this->pivots)) {
            $lastIndex = count($this->pivots) - 1;
            $this->pivots[$lastIndex] = new ZigZagPoint(
                $index,
                $price,
                $type,
                $this->candles[$index]->time
            );
        }
    }

    private function addFinalPoint(): void
    {
        if ($this->lastPivotIndex === null) return;

        $lastCandle = end($this->candles);
        $lastIndex = array_key_last($this->candles);

        if ($this->lastPivotType === self::PEAK) {
            $price = $lastCandle->getHigh();
            $type = self::PEAK;
        } else {
            $price = $lastCandle->getLow();
            $type = self::TROUGH;
        }

        $this->addPivot($lastIndex, $price, $type);
    }
}
