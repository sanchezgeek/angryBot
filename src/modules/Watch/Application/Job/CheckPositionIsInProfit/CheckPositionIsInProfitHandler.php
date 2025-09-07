<?php

declare(strict_types=1);

namespace App\Watch\Application\Job\CheckPositionIsInProfit;

use App\Alarm\Application\Settings\AlarmSettings;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Position;
use App\Command\Position\OpenedPositions\Cache\OpenedPositionsCache;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * команда для установки изначальной точки (position, current, middle, ...)
 */
#[AsMessageHandler]
final class CheckPositionIsInProfitHandler
{
    private const PriceDistanceSelector DEFAULT_ALARM_PRICE_CHANGE = PriceDistanceSelector::Long;

    private const int ALERT_RETRY_COUNT = 2;

    public function __construct(
        private readonly AppSettingsProviderInterface $settings,
        private readonly ByBitLinearPositionService $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly LoggerInterface $appErrorLogger,
        private readonly RateLimiterFactory $positionInProfitAlertThrottlingLimiter,
        private readonly OpenedPositionsCache $openedPositionsCache,
        private readonly TradingParametersProviderInterface $tradingParameters,
    ) {
    }

    public function __invoke(CheckPositionIsInProfit $message): void
    {
        if ($this->isDisabledAtAll()) return;

        /** @var $positions array<Position[]> */
        $positions = $this->positionService->getAllPositions();
        $lastPrices = $this->positionService->getLastMarkPrices();
        $watchList = $this->openedPositionsCache->getSymbolsToWatch();

        foreach ($positions as $symbolRaw => $symbolPositions) {
            if (!in_array($symbolRaw, $watchList, true)) {
                continue;
            }

            $currentMarkPrice = $lastPrices[$symbolRaw];

            foreach ($symbolPositions as $position) {
                $mutedOnPrice = null;

                $symbol = $position->symbol;
                $side = $position->side;

                if ($this->isDisabledForPosition($symbol, $side)) {
                    continue;
                }

                $refPrice = $symbol->makePrice($mutedOnPrice ?? $position->entryPrice);

                $key = $symbol->name() . '_' . $side->value;
                if (!$this->positionInProfitAlertThrottlingLimiter->create($key)->consume()->isAccepted()) {
                    continue;
                }

                $ticker = $this->exchangeService->ticker($symbol);

                $percentChange = $refPrice->differenceWith($currentMarkPrice)->getPercentChange($position->side);

                $alarmPercent = $this->getAlarmPriceChangePercent($symbol);



                $currentPnlPercent = $currentMarkPrice->differenceWith($position->entryPrice())->getPercentChange($position);
//                if ($alertOnPnlPercent = $this->settings->optional(SettingAccessor::withAlternativesAllowed(AlarmSettings::AlarmOnProfitPnlPercent, $symbol, $side))) {
//                    $alertPercentSpecifiedManually = true;
//                } else {
//                    $alertOnPnlPercent = self::SYMBOLS_ALERT_PNL_PERCENT_DEFAULT[$symbol->name()] ?? self::SYMBOLS_ALERT_PNL_PERCENT_DEFAULT['other'];
//                    $alertPercentSpecifiedManually = false;
//                }

                if ($percentChange->value() > $alarmPercent->value()) {

                    $desc = sprintf('+%.f%% from position.entry', $currentPnlPercent);
                    if ($mutedOnPrice) {
                        $percentChangeFromLastMute = $currentMarkPrice->differenceWith($position->entryPrice())->getPercentChange($position)->setOutputFloatPrecision(2);
                        $desc .= sprintf(' (and + %s from last muted price [%s])', $percentChangeFromLastMute, $mutedOnPrice);
                    }

                    $msg = sprintf('[%s] profit%s = %s %s', $position, $symbol->associatedCoin()->value, $position->unrealizedPnl, $desc);

                    // нужно откуда-то взять изменение pnl (usdt / pct)
                    for ($i = 0; $i < self::ALERT_RETRY_COUNT; $i++) {
                        $this->appErrorLogger->error(
                            $msg
                        );
                    }
                }
            }
        }
    }

    private function isDisabledForPosition(SymbolInterface $symbol, Side $side): bool
    {
        return $this->settings->optional(SettingAccessor::exact(AlarmSettings::AlarmOnProfitEnabled, $symbol, $side)) === false;
    }

    private function isDisabledAtAll(): bool
    {
        return $this->settings->optional(SettingAccessor::exact(AlarmSettings::AlarmOnProfitEnabled)) === false;
    }

    private function getAlarmPriceChangePercent(SymbolInterface $symbol): Percent
    {
        $standardAtr = $this->tradingParameters->standardAtrForOrdersLength($symbol)->percentChange->value();

        $length = self::DEFAULT_ALARM_PRICE_CHANGE;

        $result = match ($length) {
            PriceDistanceSelector::VeryVeryShort => $standardAtr / 6,
            PriceDistanceSelector::VeryShort => $standardAtr / 5,
            PriceDistanceSelector::Short => $standardAtr / 4,
            PriceDistanceSelector::BetweenShortAndStd => $standardAtr / 3.5,
            PriceDistanceSelector::Standard => $standardAtr / 3,
            PriceDistanceSelector::BetweenLongAndStd => $standardAtr / 2.5,
            PriceDistanceSelector::Long => $standardAtr / 2,
            PriceDistanceSelector::VeryLong => $standardAtr,
            PriceDistanceSelector::VeryVeryLong => $standardAtr * 1.5,
            PriceDistanceSelector::DoubleLong => $standardAtr * 2,
        };

        return Percent::notStrict($result);
    }
}
