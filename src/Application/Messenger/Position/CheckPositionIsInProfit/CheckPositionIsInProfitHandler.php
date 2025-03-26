<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsInProfit;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final class CheckPositionIsInProfitHandler
{
    private const ENABLED = true;

    private const SUPPRESSED_FOR_SYMBOLS = CheckPositionIsInProfitParams::SUPPRESSED_FOR_SYMBOLS;
    private const SYMBOLS_ALERT_PNL_PERCENT = CheckPositionIsInProfitParams::SYMBOLS_ALERT_PNL_PERCENT;

    /** @todo | MainSymbols DRY? */
    private const SYMBOLS_ALERT_PNL_PERCENT_DEFAULT = [
        Symbol::BTCUSDT->value => 150,
        Symbol::ETHUSDT->value => 300,
        'other' => 1000
    ];

    private const ALERT_RETRY_COUNT = 5;

    public function __invoke(CheckPositionIsInProfit $message): void
    {
        if (!self::ENABLED) {
            return;
        }

        /** @var $positions array<Position[]> */
        $positions = $this->positionService->getAllPositions();

        foreach ($positions as $symbolPositions) {
            foreach ($symbolPositions as $position) {
                $symbol = $position->symbol;
                $side = $position->side;

                if (
                    in_array($symbol, self::SUPPRESSED_FOR_SYMBOLS, true)
                    || in_array([$symbol, $side], self::SUPPRESSED_FOR_SYMBOLS, true)
                ) {
                    continue;
                }

                $key = $symbol->value . '_' . $side->value;
                if (!$this->positionInProfitAlertThrottlingLimiter->create($key)->consume()->isAccepted()) {
                    continue;
                }

                $ticker = $this->exchangeService->ticker($symbol);
                $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);

                if (isset(self::SYMBOLS_ALERT_PNL_PERCENT[$symbol->value])) {
                    $alertOnPnlPercent = self::SYMBOLS_ALERT_PNL_PERCENT[$symbol->value];
                    $alertPercentSpecifiedManually = true;
                } else {
                    $alertOnPnlPercent = self::SYMBOLS_ALERT_PNL_PERCENT_DEFAULT[$symbol->value] ?? self::SYMBOLS_ALERT_PNL_PERCENT_DEFAULT['other'];
                    $alertPercentSpecifiedManually = false;
                }

                if ($currentPnlPercent > $alertOnPnlPercent) {
                    for ($i = 0; $i < self::ALERT_RETRY_COUNT; $i++) {
                        $this->appErrorLogger->error(
                            sprintf(
                                '%s profit%% = %.1f%% (greater than %s%%%s)',
                                $position->getCaption(),
                                $currentPnlPercent,
                                $alertOnPnlPercent,
                                $alertPercentSpecifiedManually ? ' [specified manually]' : ''
                            )
                        );
                    }
                }
            }
        }
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly LoggerInterface $appErrorLogger,
        private readonly RateLimiterFactory $positionInProfitAlertThrottlingLimiter,
    ) {
    }
}
