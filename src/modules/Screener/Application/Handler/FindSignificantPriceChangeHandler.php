<?php

declare(strict_types=1);

namespace App\Screener\Application\Handler;

use App\Domain\Value\Percent\Percent;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Screener\Application\Contract\Dto\PriceChangeInfo;
use App\Screener\Application\Contract\Query\FindSignificantPriceChange;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeHandlerInterface;
use App\Screener\Application\Contract\Query\FindSignificantPriceChangeResponse;
use App\Screener\Application\Service\Exception\CandlesHistoryNotFound;
use App\Screener\Application\Service\PreviousSymbolPriceProvider;
use App\Screener\Application\Settings\ScreenerEnabledHandlersSettings;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateInterval;
use DateTimeImmutable;

/**
 * @todo | priceChange | some very short period handler
 * @todo | settings expiration datetime
 */
final readonly class FindSignificantPriceChangeHandler implements FindSignificantPriceChangeHandlerInterface
{
    public function handle(FindSignificantPriceChange $message): array
    {
//        $date = date_create_immutable()->sub(new DateInterval(sprintf('P%dD', $message->daysDelta)));
        $daysDelta = $message->daysDelta;
        $fromDate = date_create_immutable()->setTime(0, 0);
        if ($daysDelta) {
            $fromDate = $fromDate->sub(new DateInterval(sprintf('P%dD', $daysDelta)));
        }

        $toDate = date_create_immutable();
        $partOfDayPassed = ($toDate->getTimestamp() - $fromDate->getTimestamp()) / 86400;

        if ($partOfDayPassed < 0.1) {
            return []; // ??? to do
        }

        // возможно $daysDelta надо уменьшать, если позиция открыта и цена ушла в сторону прибыли

        $res = [];
        foreach ($this->exchangeService->getAllTickers($message->settleCoin) as $ticker) {
            $symbol = $ticker->symbol;

            if ($this->disabledFor($symbol)) {
                continue;
            }

            if (!$prevPrice = $this->getPrevPrice($symbol, $fromDate)) {
                continue;
            }

            $currentPrice = $ticker->lastPrice;
            $delta = $currentPrice->value() - $prevPrice;

            $significantPriceChangePercent = $this->parameters->significantPriceChange($symbol, $partOfDayPassed);

            $significantPriceChange = $significantPriceChangePercent->of($prevPrice);
            if ($partOfDayPassed < 0.15) {
                $significantPriceChange *= 2;
            }

            if (abs($delta) > $significantPriceChange) {
                $significantPriceDeltaPercent = Percent::fromPart($significantPriceChange / $prevPrice, false);

                $res[] = new FindSignificantPriceChangeResponse(
                    new PriceChangeInfo(
                        $symbol,
                        $fromDate,
                        $symbol->makePrice($prevPrice),
                        $toDate,
                        $currentPrice,
                        $partOfDayPassed
                    ),
                    $significantPriceDeltaPercent
                );
            }
        }

        return $res;
    }

    private function disabledFor(SymbolInterface $symbol): bool
    {
        return $this->settings->optional(
            SettingAccessor::withAlternativesAllowed(ScreenerEnabledHandlersSettings::SignificantPriceChange_Screener_Enabled, $symbol)
        ) === false;
    }

    private function getPrevPrice(SymbolInterface $symbol, DateTimeImmutable $date): ?float
    {
        try {
            return $this->previousSymbolPriceManager->getPrevPrice($symbol, $date);
        } catch (CandlesHistoryNotFound) {
            return null;
        }
    }

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $parameters,
        private ByBitLinearExchangeService $exchangeService,
        private PreviousSymbolPriceProvider $previousSymbolPriceManager,
    ) {
    }
}
