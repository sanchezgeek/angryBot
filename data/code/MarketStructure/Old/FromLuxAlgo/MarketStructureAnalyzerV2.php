<?php

declare(strict_types=1);

namespace App\TechnicalAnalysis\Application\MarketStructure\Old\FromLuxAlgo;

use App\Helper\OutputHelper;

final class MarketStructureAnalyzerV2
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
//        $this->updateBoxes($idx, $candle['close']);
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
        // Для BU-OB (бычий ордер-блок) при восходящей структуре
        if ($this->market === 1) {
            // Проверяем наличие необходимых данных
            if (count($this->low_indexes) < 2 || count($this->high_indexes) < 2) return;

            $h1i = $this->high_indexes[count($this->high_indexes)-2];
            $l0i = $this->low_indexes[count($this->low_indexes)-1];

            // Поиск последней медвежьей свечи в диапазоне
            $bu_ob_idx = null;
            for ($i = $l0i; $i >= max(0, $h1i - $this->zigzag_len); $i--) {
                if (!isset($this->history[$i])) continue;

                $candle = $this->history[$i];
                if ($candle['open'] > $candle['close']) {
                    $bu_ob_idx = $i;
                    break;
                }
            }

            if ($bu_ob_idx !== null) {
                $box = [
                    'start' => $bu_ob_idx,
                    'startDatetime' => date('Y-m-d H:i:s', $this->history[$bu_ob_idx]['time']),
                    'high' => $this->history[$bu_ob_idx]['high'],
                    'low' => $this->history[$bu_ob_idx]['low'],
                    'type' => 'BU-OB'
                ];

                if (!array_search($box, $this->bu_ob_boxes)) {
                    $this->bu_ob_boxes[] = $box;


                    $message = "🚀 Бычий ордер-блок (BU-OB)\n";
                    $message .= "{$box['low']} - {$box['high']}\n";
                    $message .= "startDatetime: {$box['startDatetime']}\n";
//                $message .= "Текущая цена: {$box['price']}";

//                    OutputHelper::print($message);
                }




//                $this->bu_ob_boxes[] = $arr;
            }
        }

        // Для BE-OB (медвежий ордер-блок) при нисходящей структуре
        elseif ($this->market === -1) {
            // Проверяем наличие необходимых данных
            if (count($this->low_indexes) < 2 || count($this->high_indexes) < 2) return;

            $l1i = $this->low_indexes[count($this->low_indexes)-2];
            $h0i = $this->high_indexes[count($this->high_indexes)-1];

            // Поиск последней бычьей свечи в диапазоне
            $be_ob_idx = null;
            for ($i = $h0i; $i >= max(0, $l1i - $this->zigzag_len); $i--) {
                if (!isset($this->history[$i])) continue;

                $candle = $this->history[$i];
                if ($candle['open'] < $candle['close']) {
                    $be_ob_idx = $i;
                    break;
                }
            }

            if ($be_ob_idx !== null) {
                $date = date('Y-m-d H:i:s', $this->history[$be_ob_idx]['time']);
                $box = [
                    'start' => $be_ob_idx,
                    'startDatetime' => $date,
                    'high' => $this->history[$be_ob_idx]['high'],
                    'low' => $this->history[$be_ob_idx]['low'],
                    'type' => 'BE-OB'
                ];

                if (!isset($this->be_ob_boxes[$date])) {
                    $this->be_ob_boxes[$date] = $box;

                    $message = "🐻 Медвежий ордер-блок (BE-OB)\n";
                    $message .= "{$box['low']} - {$box['high']}\n";
                    $message .= "startDatetime: {$box['startDatetime']}\n";
                    OutputHelper::print($message);
                }



//                $message .= "Текущая цена: {$signal['price']}";

//                $this->be_ob_boxes[] = $arr1;
            }
        }

        // Обновление и очистка блоков
//        $this->updateOrderBlocks($idx);
    }

    private function updateOrderBlocks(int $currentIdx): void {
        $close = $this->history[$currentIdx]['close'];

        // Обновление BU-OB блоков
        foreach ($this->bu_ob_boxes as $key => $box) {
            $top = $box['high'];
            $bottom = $box['low'];

            if ($close < $bottom) {
                unset($this->bu_ob_boxes[$key]);
            } elseif ($close < $top) {
                $this->triggerSignal('BU-OB', $box, $close);
            }
        }

        // Обновление BE-OB блоков
        foreach ($this->be_ob_boxes as $key => $box) {
            $top = $box['high'];
            $bottom = $box['low'];

            if ($close > $top) {
                unset($this->be_ob_boxes[$key]);
            } elseif ($close > $bottom) {
                $this->triggerSignal('BE-OB', $box, $close);
            }
        }

        // Переиндексация массивов после удаления
        $this->bu_ob_boxes = array_values($this->bu_ob_boxes);
        $this->be_ob_boxes = array_values($this->be_ob_boxes);
    }

    private function triggerSignal(string $type, array $box, float $price): void {
        return;
        // Проверяем, не активировали ли мы уже сигнал для этого блока
        if (isset($box['triggered']) && $box['triggered']) return;

        // Помечаем блок как активированный
        $box['triggered'] = true;

        // Генерируем сигнал
        $signal = [
            'type' => $type,
            'price' => $price,
            'box_high' => $box['high'],
            'box_low' => $box['low'],
            'timestamp' => time()
        ];

        // Отправляем вебхук
        $this->sendWebhook($signal);
//
//        // Логируем событие
//        $this->logSignal($signal);
    }

    private function sendWebhook(array $signal): void {
        $message = '';
        switch ($signal['type']) {
            case 'BU-OB':
                $message = "🚀 Бычий ордер-блок (BU-OB)\n";
                $message .= "Цена вошла в зону: {$signal['box_low']} - {$signal['box_high']}\n";
                $message .= "Текущая цена: {$signal['price']}";
                break;

            case 'BE-OB':
                $message = "🐻 Медвежий ордер-блок (BE-OB)\n";
                $message .= "Цена вошла в зону: {$signal['box_low']} - {$signal['box_high']}\n";
                $message .= "Текущая цена: {$signal['price']}";
                break;
        }

        OutputHelper::print('', $message, '');

//// Отправляем в Telegram
//        $telegramUrl = "https://api.telegram.org/botTELEGRAM_BOT_TOKEN/sendMessage";
//        $postData = [
//            'chat_id' => 'YOUR_CHAT_ID',
//            'text' => $message,
//            'parse_mode' => 'HTML'
//        ];
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
