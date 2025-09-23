<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\Trading\Application\AutoOpen\Decision\OpenPositionConfidenceRateDecisionVoterInterface;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class InstrumentAgeIsAppropriateCriteriaHandlerDraft implements OpenPositionPrerequisiteCheckerInterface, OpenPositionConfidenceRateDecisionVoterInterface
{
    public const int ABSOLUTE_MINIMUM_AGE_DAYS = 3; // Абсолютный минимум
    public const int RECOMMENDED_MINIMUM_AGE_DAYS = 7; // Рекомендуемый минимум
    public const int MATURE_AGE_DAYS = 30; // Возраст "зрелости"

    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return false;
    }

    public function supportsMakeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return false;
    }

//    public function checkCriteria(
//        InitialPositionAutoOpenClaim $claim,
//        AbstractOpenPositionCriteria|InstrumentAgeIsAppropriateCriteria $criteria
//    ): OpenPositionPrerequisiteCheckResult {
//        $symbol = $claim->symbol;
//        $age = TA::instrumentAge($symbol);
//
//        // Абсолютный минимальный порог
//        if ($age->countOfDays() < self::ABSOLUTE_MINIMUM_AGE_DAYS) {
//            return new OpenPositionPrerequisiteCheckResult(
//                false,
//                OutputHelper::shortClassName(self::class),
//                sprintf('Age of %s is less than absolute minimum %d days (%s)',
//                    $symbol->name(),
//                    self::ABSOLUTE_MINIMUM_AGE_DAYS,
//                    $age)
//            );
//        }
//
//        // Дополнительная проверка объема для очень молодых активов
//        if ($age->countOfDays() < self::RECOMMENDED_MINIMUM_AGE_DAYS) {
//            $volumeAnalysis = $this->analyzeVolumeStability($symbol);
//            if (!$volumeAnalysis['is_stable']) {
//                return new OpenPositionPrerequisiteCheckResult(
//                    false,
//                    OutputHelper::shortClassName(self::class),
//                    sprintf('Age of %s is %d days but volume is not stable enough',
//                        $symbol->name(),
//                        $age->countOfDays())
//                );
//            }
//        }
//
//        return new OpenPositionPrerequisiteCheckResult(
//            true,
//            OutputHelper::shortClassName(self::class),
//            sprintf('Instrument age = %s', $age)
//        );
//    }

    public function checkCriteria(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|InstrumentAgeIsAppropriateCriteria $criteria
    ): OpenPositionPrerequisiteCheckResult {
        $symbol = $claim->symbol;
        $age = TA::instrumentAge($symbol);

        // Проверка возраста
        if ($age->countOfDays() < self::ABSOLUTE_MINIMUM_AGE_DAYS) {
            return new OpenPositionPrerequisiteCheckResult();
        }

        // Дополнительные проверки для молодых активов
        if ($age->countOfDays() < self::RECOMMENDED_MINIMUM_AGE_DAYS) {
            $liquidityAnalysis = $this->analyzeLiquidity($symbol);
            $manipulationAnalysis = $this->analyzeMarketManipulationSigns($symbol);

            if (!$liquidityAnalysis['is_liquid'] || $manipulationAnalysis['has_manipulation_signs']) {
                return new OpenPositionPrerequisiteCheckResult();
            }
        }

        return new OpenPositionPrerequisiteCheckResult(
            true,
            OutputHelper::shortClassName(self::class),
            sprintf('Instrument age = %s', $age)
        );
    }

    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRateDecision
    {
        $symbol = $claim->symbol;
        $age = TA::instrumentAge($symbol);
        $ageDays = $age->countOfDays();

        $ageConfidence = $this->calculateAgeConfidence($ageDays);

        // Для молодых активов учитываем ликвидность и манипуляции
        if ($ageDays < self::MATURE_AGE_DAYS) {
            $liquidityAnalysis = $this->analyzeLiquidity($symbol);
            $manipulationAnalysis = $this->analyzeMarketManipulationSigns($symbol);

            $liquidityScore = $liquidityAnalysis['liquidity_score'];
            $manipulationScore = 1 - $manipulationAnalysis['manipulation_score']; // Инвертируем score манипуляций

            $finalConfidence = $ageConfidence * $liquidityScore * $manipulationScore;
        } else {
            $finalConfidence = $ageConfidence;
        }

        return new ConfidenceRateDecision();
    }

//    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRate
//    {
//        $symbol = $claim->symbol;
//        $age = TA::instrumentAge($symbol);
//        $ageDays = $age->countOfDays();
//
//        // Базовый расчет уверенности на основе возраста
//        $ageConfidence = $this->calculateAgeConfidence($ageDays);
//
//        // Корректировка на основе объема (если актив молодой)
//        if ($ageDays < self::MATURE_AGE_DAYS) {
//            $volumeAnalysis = $this->analyzeVolumeStability($symbol);
//            $volumeConfidence = $this->calculateVolumeConfidence($volumeAnalysis);
//            $finalConfidence = min($ageConfidence, $volumeConfidence);
//        } else {
//            $finalConfidence = $ageConfidence;
//        }
//
//        return new ConfidenceRate(
//            OutputHelper::shortClassName($this),
//            Percent::fromPart($finalConfidence, false),
//            sprintf('Age confidence: %.2f (%d days)', $finalConfidence, $ageDays)
//        );
//    }

    private function calculateAgeConfidence(float $ageDays): float
    {
        // Нелинейное преобразование: уверенность быстро растет первые 30 дней, затем медленнее
        if ($ageDays >= 90) {
            return 1.0; // Максимальная уверенность для активов старше 90 дней
        }

        // Логистическая функция для плавного роста уверенности
        $k = 0.1; // Коэффициент крутизны
        $x0 = 30;  // Точка перегиба (30 дней)
        return 1 / (1 + exp(-$k * ($ageDays - $x0)));
    }

    private function analyzeVolumeStability(SymbolInterface $symbol): array
    {
        $candles = $this->candleService->getRecentCandles($symbol, '1d', 14);
        $volumes = array_column($candles, 'volume');

        // Рассчитываем коэффициент вариации объема
        $meanVolume = array_sum($volumes) / count($volumes);
        $variance = 0;

        foreach ($volumes as $volume) {
            $variance += pow($volume - $meanVolume, 2);
        }

        $stdDev = sqrt($variance / count($volumes));
        $coefficientOfVariation = $meanVolume > 0 ? $stdDev / $meanVolume : 1;

        // Анализируем минимальный объем
        $minVolume = min($volumes);
        $maxVolume = max($volumes);
        $volumeRatio = $maxVolume > 0 ? $minVolume / $maxVolume : 0;

        return [
            'is_stable' => $coefficientOfVariation < 0.5 && $volumeRatio > 0.3,
            'coefficient_of_variation' => $coefficientOfVariation,
            'volume_ratio' => $volumeRatio,
            'mean_volume' => $meanVolume,
        ];
    }

    private function calculateVolumeConfidence(array $volumeAnalysis): float
    {
        // Уверенность на основе стабильности объема
        $cvScore = max(0, 1 - $volumeAnalysis['coefficient_of_variation']);
        $ratioScore = $volumeAnalysis['volume_ratio'];

        return ($cvScore * 0.7) + ($ratioScore * 0.3);
    }

    private function analyzeLiquidity(SymbolInterface $symbol): array
    {
        try {
            // Получаем данные стакана заказов
            $orderBook = $this->exchangeService->getOrderBook($symbol, 50); // 50 лучших ордеров с каждой стороны

            // Получаем данные о последних сделках
            $recentTrades = $this->exchangeService->getRecentTrades($symbol, 100); // 100 последних сделок

            // Рассчитываем спред между лучшим bid и ask
            $bestBid = $orderBook['bids'][0]['price'] ?? 0;
            $bestAsk = $orderBook['asks'][0]['price'] ?? 0;
            $spread = $bestAsk > 0 && $bestBid > 0 ? ($bestAsk - $bestBid) / $bestAsk * 100 : 0;

            // Анализируем глубину стакана
            $bidDepth = $this->calculateDepth($orderBook['bids']);
            $askDepth = $this->calculateDepth($orderBook['asks']);
            $depthRatio = $bidDepth > 0 ? $askDepth / $bidDepth : 1;

            // Анализируем объемы в стакане
            $bidVolume = $this->calculateTotalVolume($orderBook['bids']);
            $askVolume = $this->calculateTotalVolume($orderBook['asks']);
            $volumeRatio = $bidVolume > 0 ? $askVolume / $bidVolume : 1;

            // Анализируем объем последних сделок
            $tradeVolume = $this->calculateTradeVolume($recentTrades);
            $tradeCount = count($recentTrades);

            // Оцениваем наличие маркет-мейкеров (косвенно)
            $hasMarketMakers = $this->detectMarketMakers($orderBook, $recentTrades);

            return [
                'spread_percent' => $spread,
                'bid_depth' => $bidDepth,
                'ask_depth' => $askDepth,
                'depth_ratio' => $depthRatio,
                'bid_volume' => $bidVolume,
                'ask_volume' => $askVolume,
                'volume_ratio' => $volumeRatio,
                'trade_volume' => $tradeVolume,
                'trade_count' => $tradeCount,
                'has_market_makers' => $hasMarketMakers,
                'liquidity_score' => $this->calculateLiquidityScore($spread, $bidDepth, $askDepth, $tradeVolume),
                'is_liquid' => $spread < 0.1 && $bidDepth > 10000 && $tradeVolume > 100000,
            ];

        } catch (\Exception $e) {
            // В случае ошибки возвращаем консервативные значения
            return [
                'spread_percent' => 1.0,
                'bid_depth' => 0,
                'ask_depth' => 0,
                'depth_ratio' => 1,
                'bid_volume' => 0,
                'ask_volume' => 0,
                'volume_ratio' => 1,
                'trade_volume' => 0,
                'trade_count' => 0,
                'has_market_makers' => false,
                'liquidity_score' => 0,
                'is_liquid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function calculateDepth(array $orders): float
    {
        // Рассчитываем глубину как объем в пределах 2% от лучшей цены
        if (empty($orders)) return 0;

        $bestPrice = $orders[0]['price'];
        $depthVolume = 0;

        foreach ($orders as $order) {
            $priceDiff = abs($order['price'] - $bestPrice) / $bestPrice * 100;
            if ($priceDiff <= 2) {
                $depthVolume += $order['quantity'] * $order['price'];
            } else {
                break;
            }
        }

        return $depthVolume;
    }

    private function calculateTotalVolume(array $orders): float
    {
        $totalVolume = 0;
        foreach ($orders as $order) {
            $totalVolume += $order['quantity'] * $order['price'];
        }
        return $totalVolume;
    }

    private function calculateTradeVolume(array $trades): float
    {
        $volume = 0;
        foreach ($trades as $trade) {
            $volume += $trade['quantity'] * $trade['price'];
        }
        return $volume;
    }

    private function detectMarketMakers(array $orderBook, array $trades): bool
    {
        // Косвенные признаки наличия маркет-мейкеров:
        // 1. Большие объемы на нескольких уровнях стакана
        // 2. Частые крупные сделки по лучшим ценам
        // 3. Маленький спред

        $largeOrdersCount = 0;
        foreach ($orderBook['bids'] as $order) {
            if ($order['quantity'] * $order['price'] > 10000) { // Ордера больше $10,000
                $largeOrdersCount++;
            }
        }

        foreach ($orderBook['asks'] as $order) {
            if ($order['quantity'] * $order['price'] > 10000) {
                $largeOrdersCount++;
            }
        }

        $largeTradesCount = 0;
        foreach ($trades as $trade) {
            if ($trade['quantity'] * $trade['price'] > 5000) { // Сделки больше $5,000
                $largeTradesCount++;
            }
        }

        return $largeOrdersCount >= 5 && $largeTradesCount >= 3;
    }

    private function calculateLiquidityScore(float $spread, float $bidDepth, float $askDepth, float $tradeVolume): float
    {
        // Нормализуем и взвешиваем различные факторы ликвидности
        $spreadScore = max(0, 1 - min($spread, 1.0)); // Спред 0% = 1, 1% = 0
        $depthScore = min(1, ($bidDepth + $askDepth) / 50000); // $50,000 глубины = 1
        $volumeScore = min(1, $tradeVolume / 250000); // $250,000 объема = 1

        return ($spreadScore * 0.4) + ($depthScore * 0.3) + ($volumeScore * 0.3);
    }

    private function analyzeMarketManipulationSigns(SymbolInterface $symbol): array
    {
        try {
            // Получаем свечные данные за последние 7 дней
            $candles = $this->candleService->getRecentCandles($symbol, '1h', 168); // 168 часов = 7 дней

            // Анализируем различные признаки манипуляций
            $volumeSpikes = $this->detectVolumeSpikes($candles);
            $priceSpikes = $this->detectPriceSpikes($candles);
            $wickAnalysis = $this->analyzeWicks($candles);
            $volumePriceDivergence = $this->detectVolumePriceDivergence($candles);
            $pumpAndDumpPatterns = $this->detectPumpAndDumpPatterns($candles);

            // Считаем общий показатель манипуляций
            $manipulationScore = $this->calculateManipulationScore(
                $volumeSpikes,
                $priceSpikes,
                $wickAnalysis,
                $volumePriceDivergence,
                $pumpAndDumpPatterns
            );

            return [
                'volume_spikes' => $volumeSpikes,
                'price_spikes' => $priceSpikes,
                'wick_anomalies' => $wickAnalysis,
                'volume_price_divergence' => $volumePriceDivergence,
                'pump_and_dump_patterns' => $pumpAndDumpPatterns,
                'manipulation_score' => $manipulationScore,
                'has_manipulation_signs' => $manipulationScore > 0.7,
                'manipulation_risk' => $this->getRiskLevel($manipulationScore),
            ];

        } catch (\Exception $e) {
            return [
                'volume_spikes' => [],
                'price_spikes' => [],
                'wick_anomalies' => [],
                'volume_price_divergence' => [],
                'pump_and_dump_patterns' => [],
                'manipulation_score' => 0,
                'has_manipulation_signs' => false,
                'manipulation_risk' => 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }

    private function detectVolumeSpikes(array $candles): array
    {
        $spikes = [];
        $volumes = array_column($candles, 'volume');
        $averageVolume = array_sum($volumes) / count($volumes);
        $stdDev = $this->calculateStdDev($volumes);

        for ($i = 0; $i < count($candles); $i++) {
            $volume = $candles[$i]['volume'];
            $zScore = $stdDev > 0 ? ($volume - $averageVolume) / $stdDev : 0;

            if ($zScore > 3) { // Объем более чем в 3 стандартных отклонения от среднего
                $spikes[] = [
                    'index' => $i,
                    'timestamp' => $candles[$i]['timestamp'],
                    'volume' => $volume,
                    'z_score' => $zScore,
                    'price' => $candles[$i]['close'],
                ];
            }
        }

        return $spikes;
    }

    private function detectPriceSpikes(array $candles): array
    {
        $spikes = [];

        for ($i = 1; $i < count($candles) - 1; $i++) {
            $current = $candles[$i];
            $previous = $candles[$i - 1];
            $next = $candles[$i + 1];

            $priceChange = abs($current['close'] - $previous['close']) / $previous['close'] * 100;
            $nextChange = abs($next['close'] - $current['close']) / $current['close'] * 100;

            // Резкое изменение цены с последующим откатом
            if ($priceChange > 5 && $nextChange > 3) { // 5% изменение с 3% откатом
                $spikes[] = [
                    'index' => $i,
                    'timestamp' => $current['timestamp'],
                    'price_change' => $priceChange,
                    'reversal_change' => $nextChange,
                    'volume' => $current['volume'],
                ];
            }
        }

        return $spikes;
    }

    private function analyzeWicks(array $candles): array
    {
        $anomalies = [];

        foreach ($candles as $i => $candle) {
            $bodySize = abs($candle['close'] - $candle['open']);
            $totalRange = $candle['high'] - $candle['low'];

            if ($totalRange > 0) {
                $upperWickRatio = ($candle['high'] - max($candle['open'], $candle['close'])) / $totalRange;
                $lowerWickRatio = (min($candle['open'], $candle['close']) - $candle['low']) / $totalRange;

                // Длинные верхние или нижние тени относительно тела свечи
                if (($upperWickRatio > 0.7 && $bodySize / $totalRange < 0.3) ||
                    ($lowerWickRatio > 0.7 && $bodySize / $totalRange < 0.3)) {
                    $anomalies[] = [
                        'index' => $i,
                        'timestamp' => $candle['timestamp'],
                        'upper_wick_ratio' => $upperWickRatio,
                        'lower_wick_ratio' => $lowerWickRatio,
                        'body_ratio' => $bodySize / $totalRange,
                        'price' => $candle['close'],
                    ];
                }
            }
        }

        return $anomalies;
    }

    private function detectVolumePriceDivergence(array $candles): array
    {
        $divergences = [];

        for ($i = 5; $i < count($candles); $i++) {
            // Скользящие средние для цены и объема
            $priceMa = $this->calculateMovingAverage(array_slice($candles, $i - 5, 5), 'close');
            $volumeMa = $this->calculateMovingAverage(array_slice($candles, $i - 5, 5), 'volume');

            $currentPrice = $candles[$i]['close'];
            $currentVolume = $candles[$i]['volume'];

            // Дивергенция: цена растет, но объем падает
            if ($currentPrice > $priceMa * 1.05 && $currentVolume < $volumeMa * 0.7) {
                $divergences[] = [
                    'index' => $i,
                    'timestamp' => $candles[$i]['timestamp'],
                    'price_change' => ($currentPrice - $priceMa) / $priceMa * 100,
                    'volume_change' => ($currentVolume - $volumeMa) / $volumeMa * 100,
                    'type' => 'bearish_divergence',
                ];
            }

            // Дивергенция: цена падает, но объем растет
            if ($currentPrice < $priceMa * 0.95 && $currentVolume > $volumeMa * 1.3) {
                $divergences[] = [
                    'index' => $i,
                    'timestamp' => $candles[$i]['timestamp'],
                    'price_change' => ($currentPrice - $priceMa) / $priceMa * 100,
                    'volume_change' => ($currentVolume - $volumeMa) / $volumeMa * 100,
                    'type' => 'bullish_divergence',
                ];
            }
        }

        return $divergences;
    }

    private function detectPumpAndDumpPatterns(array $candles): array
    {
        $patterns = [];

        for ($i = 10; $i < count($candles) - 5; $i++) {
            // Ищем резкий рост цены с последующим падением
            $growthPeriod = array_slice($candles, $i - 10, 10);
            $declinePeriod = array_slice($candles, $i, 5);

            $growth = $this->calculatePriceChange($growthPeriod);
            $decline = $this->calculatePriceChange($declinePeriod);

            // Рост более 20% с последующим падением более 15%
            if ($growth > 20 && $decline < -15) {
                $volumeGrowth = $this->calculateVolumeChange($growthPeriod);
                $volumeDecline = $this->calculateVolumeChange($declinePeriod);

                // Типичный паттерн pump-and-dump: высокий объем на росте, низкий на падении
                if ($volumeGrowth > 50 && $volumeDecline < -30) {
                    $patterns[] = [
                        'start_index' => $i - 10,
                        'end_index' => $i + 5,
                        'start_timestamp' => $candles[$i - 10]['timestamp'],
                        'end_timestamp' => $candles[$i + 5]['timestamp'],
                        'price_change' => $growth + $decline, // Общее изменение
                        'volume_growth' => $volumeGrowth,
                        'volume_decline' => $volumeDecline,
                    ];
                }
            }
        }

        return $patterns;
    }

    private function calculateManipulationScore(
        array $volumeSpikes,
        array $priceSpikes,
        array $wickAnomalies,
        array $volumePriceDivergence,
        array $pumpAndDumpPatterns
    ): float {
        // Взвешиваем различные признаки манипуляций
        $score = 0;

        // Веса различных признаков
        $weights = [
            'volume_spikes' => 0.2,
            'price_spikes' => 0.25,
            'wick_anomalies' => 0.15,
            'volume_price_divergence' => 0.2,
            'pump_and_dump_patterns' => 0.2,
        ];

        // Нормализуем количество каждого признака
        $maxValues = [
            'volume_spikes' => 10,
            'price_spikes' => 5,
            'wick_anomalies' => 15,
            'volume_price_divergence' => 8,
            'pump_and_dump_patterns' => 3,
        ];

        $score += min(count($volumeSpikes) / $maxValues['volume_spikes'], 1) * $weights['volume_spikes'];
        $score += min(count($priceSpikes) / $maxValues['price_spikes'], 1) * $weights['price_spikes'];
        $score += min(count($wickAnomalies) / $maxValues['wick_anomalies'], 1) * $weights['wick_anomalies'];
        $score += min(count($volumePriceDivergence) / $maxValues['volume_price_divergence'], 1) * $weights['volume_price_divergence'];
        $score += min(count($pumpAndDumpPatterns) / $maxValues['pump_and_dump_patterns'], 1) * $weights['pump_and_dump_patterns'];

        return min($score, 1.0);
    }

    private function getRiskLevel(float $score): string
    {
        if ($score < 0.3) return 'low';
        if ($score < 0.6) return 'medium';
        if ($score < 0.8) return 'high';
        return 'extreme';
    }

// Вспомогательные методы
    private function calculateStdDev(array $values): float
    {
        $mean = array_sum($values) / count($values);
        $variance = 0.0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / count($values));
    }

    private function calculateMovingAverage(array $data, string $field): float
    {
        $sum = 0;
        foreach ($data as $item) {
            $sum += $item[$field];
        }
        return $sum / count($data);
    }

    private function calculatePriceChange(array $candles): float
    {
        if (count($candles) < 2) return 0;
        $first = $candles[0]['close'];
        $last = end($candles)['close'];
        return ($last - $first) / $first * 100;
    }

    private function calculateVolumeChange(array $candles): float
    {
        if (count($candles) < 2) return 0;
        $first = $candles[0]['volume'];
        $last = end($candles)['volume'];
        return ($last - $first) / $first * 100;
    }
}
