<?php

declare(strict_types=1);

namespace App\Screener\Application\Job\CheckSymbolsPriceChange;

use App\Application\Notification\AppNotificationLoggerInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Screener\Application\Parameters\PriceChangeDynamicParameters;
use App\Screener\Application\Service\PreviousSymbolPriceProvider;
use App\Screener\Application\Settings\ScreenerEnabledHandlersSettings;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use DateInterval;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final class CheckSymbolsPriceChangeHandler
{
    private const ALERT_RETRY_COUNT = 1;

    public function __invoke(CheckSymbolsPriceChange $message): void
    {
        if ($this->settings->required(SettingAccessor::exact(ScreenerEnabledHandlersSettings::SignificantPriceChange_Screener_Enabled)) !== true) {
            return;
        }

//        $date = date_create_immutable()->sub(new DateInterval(sprintf('P%dD', $message->daysDelta)));
        $daysDelta = $message->daysDelta;
        $date = date_create_immutable()->setTime(0, 0);
        if ($daysDelta) {
            $date = $date->sub(new DateInterval(sprintf('P%dD', $daysDelta)));
        }

        $partOfDayPassed = (date_create_immutable()->getTimestamp() - $date->getTimestamp()) / 86400;

        $tickers = $this->exchangeService->getAllTickersRaw($message->settleCoin);
        foreach ($tickers as $symbolRaw => $ticker) {
            if ($this->isSymbolSkipped($symbolRaw)) {
                continue;
            }

            $currentPrice = $ticker['last'];
            $prevPrice = $this->previousSymbolPriceManager->getPrevPrice($symbolRaw, $date);
            // @todo | symbol | provider / factory ...
            $significantPriceDelta = $this->parameters->significantPriceDelta($prevPrice, $partOfDayPassed, SymbolEnum::tryFrom($symbolRaw));
            $delta = abs($prevPrice - $currentPrice);

            if ($delta > $significantPriceDelta) {
                if (!$this->priceChangeAlarmThrottlingLimiter->create(sprintf('%s_daysDelta_%d', $symbolRaw, $daysDelta))->consume()->isAccepted()) {
                    continue;
                }

                for ($i = 0; $i < self::ALERT_RETRY_COUNT; $i++) {
                    $changePercent = Percent::fromPart($delta / $prevPrice)->setOutputFloatPrecision(2);
                    $significantPriceDeltaPercent = Percent::fromPart($significantPriceDelta / $prevPrice)->setOutputFloatPrecision(2);

                    $this->notifications->notify(
                        sprintf(
                            '%s [daysDelta=%.2f from %s].price=%s, curr.price = %s, Δ = %s (%s) (> %s [%s])',
                            $symbolRaw,
                            $partOfDayPassed,
                            $date->format('m-d H:i:s'),
                            $prevPrice,
                            $currentPrice,
                            $delta,
                            $changePercent,
                            $significantPriceDelta,
                            $significantPriceDeltaPercent
                        )
                    );
                }
            }
        }
    }

    private function isSymbolSkipped(string $symbol): bool
    {
        return $symbol === 'BTCPERP' || str_contains($symbol, 'BTCUSDT-') || str_contains($symbol, 'BTC-');
    }

    public function __construct(
        private readonly AppSettingsProviderInterface $settings,
        private readonly PriceChangeDynamicParameters $parameters,
        private readonly ByBitLinearExchangeService $exchangeService,
        private readonly PreviousSymbolPriceProvider $previousSymbolPriceManager,
        private readonly AppNotificationLoggerInterface $notifications,
        private readonly RateLimiterFactory $priceChangeAlarmThrottlingLimiter,
    ) {
    }
}
