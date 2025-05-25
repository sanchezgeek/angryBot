<?php

declare(strict_types=1);

namespace App\Screener\Application\Job\CheckSymbolsPriceChange;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Screener\Application\Parameters\PriceChangeDynamicParameters;
use App\Screener\Application\Service\PreviousSymbolPriceProvider;
use DateInterval;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final class CheckSymbolsPriceChangeHandler
{
    private const ALERT_RETRY_COUNT = 3;

    public function __invoke(CheckSymbolsPriceChange $message): void
    {
        $date = date_create_immutable()->sub($message->timeIntervalWithPrev ?? new DateInterval('P1D'));

        $tickers = $this->exchangeService->getAllTickersRaw($message->settleCoin);
        foreach ($tickers as $symbolRaw => $ticker) {
            $currentPrice = $ticker['last'];
            $prevPrice = $this->previousSymbolPriceManager->getPrevPrice($symbolRaw, $date);
            $alarmDelta = $this->parameters->alarmDelta($currentPrice, Symbol::tryFrom($symbolRaw));
            $delta = abs($prevPrice - $currentPrice);

            if ($delta > $alarmDelta) {
                if (!$this->priceChangeAlarmThrottlingLimiter->create($symbolRaw)->consume()->isAccepted()) {
                    continue;
                }
                for ($i = 0; $i < self::ALERT_RETRY_COUNT; $i++) {
                    $this->appErrorLogger->error(
                        sprintf(
                            '%s price = %s, delta with %s (on %s) = %s (greater than %s)',
                            $symbolRaw,
                            $currentPrice,
                            $prevPrice,
                            $date->format('m-d H:i:s'),
                            $delta,
                            $alarmDelta
                        )
                    );
                }
            }
        }
    }

    public function __construct(
        private readonly PriceChangeDynamicParameters $parameters,
        private readonly ByBitLinearExchangeService $exchangeService,
        private readonly PreviousSymbolPriceProvider $previousSymbolPriceManager,
        private readonly LoggerInterface $appErrorLogger,
        private readonly RateLimiterFactory $priceChangeAlarmThrottlingLimiter,
    ) {
    }
}
