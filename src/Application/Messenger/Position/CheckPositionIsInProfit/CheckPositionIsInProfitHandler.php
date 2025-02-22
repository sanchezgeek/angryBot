<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckPositionIsInProfit;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final class CheckPositionIsInProfitHandler
{
    private const SUPPRESS = [
//        Symbol::AVAAIUSDT,
//        [Symbol::XRPUSDT, Side::Sell],
    ];

    private const SYMBOLS_ALERT__PNL_PERCENT = [
//        Symbol::AVAAIUSDT->value => 4000,
    ];

    private const ALERT_ON_PNL_PERCENT = 150;
    private const ALERT_ON_PNL_PERCENT_FOR_ALT_COIN = 1000;

    private const ALERT_RETRY_COUNT = 5;

    /** @todo | DRY? */
    const MAIN_SYMBOLS = [Symbol::BTCUSDT, Symbol::ETHUSDT];

    public function __invoke(CheckPositionIsInProfit $message): void
    {
        /** @var $positions array<Position[]> */
        $positions = $this->positionService->getAllPositions();

        foreach ($positions as $symbolPositions) {
            foreach ($symbolPositions as $position) {
                $symbol = $position->symbol;
                $side = $position->side;

                if (
                    in_array($symbol, self::SUPPRESS, true)
                    || in_array([$symbol, $side], self::SUPPRESS, true)
                ) {
                    continue;
                }

                $key = $symbol->value . '_' . $side->value;
                if (!$this->positionInProfitAlertThrottlingLimiter->create($key)->consume()->isAccepted()) {
                    continue;
                }

                $ticker = $this->exchangeService->ticker($symbol);
                $currentPnlPercent = $ticker->lastPrice->getPnlPercentFor($position);

                if (isset(self::SYMBOLS_ALERT__PNL_PERCENT[$symbol->value])) {
                    $alertOnPnlPercent = self::SYMBOLS_ALERT__PNL_PERCENT[$symbol->value];
                    $alertPercentSpecifiedManually = true;
                } else {
                    $alertOnPnlPercent = in_array($symbol, self::MAIN_SYMBOLS, true) ? self::ALERT_ON_PNL_PERCENT : self::ALERT_ON_PNL_PERCENT_FOR_ALT_COIN;
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
