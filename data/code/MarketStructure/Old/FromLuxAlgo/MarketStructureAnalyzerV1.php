<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure\Old\FromLuxAlgo;

final class MarketStructureAnalyzerV1
{
    public $zigzag_len;
    public $fib_factor;
    public $history = [];
    public $high_points = [];
    public $low_points = [];
    public $high_indexes = [];
    public $low_indexes = [];
    public $trend = 1;
    public $market = 1;
    public $bu_ob_boxes = [];
    public $be_ob_boxes = [];
    public $bu_bb_boxes = [];
    public $be_bb_boxes = [];

    public function __construct(int $zigzag_len = 9, float $fib_factor = 0.33) {
        $this->zigzag_len = $zigzag_len;
        $this->fib_factor = $fib_factor;
    }

    public function processCandle(array $candle): void {
        $this->history[] = $candle;
        $idx = count($this->history) - 1;

        if ($idx < $this->zigzag_len * 2) return;

        $this->updateZigZag($idx);
        $this->updateMarketStructure($idx);
        $this->detectOrderBlocks($idx);
        $this->updateBoxes($idx, $candle['close']);
    }

    private function updateZigZag(int $idx): void {
        // Получаем окно для расчета экстремумов
        $highs = array_column(array_slice($this->history, $idx - $this->zigzag_len + 1, $this->zigzag_len), 'high');
        $lows = array_column(array_slice($this->history, $idx - $this->zigzag_len + 1, $this->zigzag_len), 'low');

        $highest_high = max($highs);
        $lowest_low = min($lows);

        $to_up = $this->history[$idx]['high'] >= $highest_high;
        $to_down = $this->history[$idx]['low'] <= $lowest_low;

        $prev_trend = $this->trend;

        if ($prev_trend === 1 && $to_down) {
            $this->trend = -1;
        } elseif ($prev_trend === -1 && $to_up) {
            $this->trend = 1;
        }

        // При изменении тренда сохраняем точку
        if ($prev_trend !== $this->trend) {
            if ($this->trend === 1) {
                $this->low_points[] = $lowest_low;
                $this->low_indexes[] = $idx - array_search($lowest_low, $lows);
            } else {
                $this->high_points[] = $highest_high;
                $this->high_indexes[] = $idx - array_search($highest_high, $highs);
            }
        }
    }

    private function updateMarketStructure(int $idx): void {
        if (count($this->low_points) < 2 || count($this->high_points) < 2) return;

        $h0 = end($this->high_points);
        $h1 = prev($this->high_points);
        $l0 = end($this->low_points);
        $l1 = prev($this->low_points);

        $prev_market = $this->market;

        if ($this->market === 1) {
            $threshold = $l1 - abs($h0 - $l1) * $this->fib_factor;
            if ($l0 < $l1 && $l0 < $threshold) {
                $this->market = -1;
            }
        } else {
            $threshold = $h1 + abs($h1 - $l0) * $this->fib_factor;
            if ($h0 > $h1 && $h0 > $threshold) {
                $this->market = 1;
            }
        }

        // При изменении структуры генерируем событие
        if ($prev_market !== $this->market) {
            $this->onMarketStructureBreak();
        }
    }

    private function detectOrderBlocks(int $idx): void {
        // Для BU-OB: поиск последней медвежьей свечи в диапазоне
        if ($this->market === 1) {
            $start_idx = $this->high_indexes[count($this->high_indexes)-2] ?? 0;
            $bu_ob_idx = $this->findLastBearishCandle($start_idx, $idx);

            if ($bu_ob_idx !== null) {
                $arr = [
                    'start' => $bu_ob_idx,
                    'high' => $this->history[$bu_ob_idx]['high'],
                    'low' => $this->history[$bu_ob_idx]['low']
                ];
                if (!array_search($arr, $this->bu_ob_boxes)) {
                    $this->bu_ob_boxes[] = $arr;
                }
            }
        }
        // Аналогично для других блоков...
    }

    private function findLastBearishCandle(int $start, int $end): ?int {
        for ($i = $end; $i >= $start; $i--) {
            if ($this->history[$i]['open'] > $this->history[$i]['close']) {
                return $i;
            }
        }
        return null;
    }

    private function updateBoxes(int $idx, float $close): void {
        // Обновление и удаление пробитых блоков
        foreach ($this->bu_ob_boxes as $k => $box) {
            if ($close < $box['low']) {
                unset($this->bu_ob_boxes[$k]);
            } elseif ($close < $box['high']) {
                $this->inOrderBlockZone('BU-OB');
            }
        }
        // Аналогично для других блоков...
    }

    private function onMarketStructureBreak(): void {
        // MSB событие - можно отправить сигнал
        file_put_contents('signals.log', date('c')." - MSB: ".($this->market===1?"Bullish":"Bearish")."\n", FILE_APPEND);
    }

    private function inOrderBlockZone(string $type): void {
        // Событие входа в зону блока
        file_put_contents('signals.log', date('c')." - Price in $type zone\n", FILE_APPEND);
    }
}
