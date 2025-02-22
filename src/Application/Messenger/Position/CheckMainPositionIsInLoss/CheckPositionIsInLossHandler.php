<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckMainPositionIsInLoss;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\RateLimiter\RateLimiterFactory;

#[AsMessageHandler]
final class CheckPositionIsInLossHandler
{
    /** @todo Store in db / Suppress by click */
    private const SUPPRESS = [
        Symbol::UROUSDT,
        Symbol::NCUSDT,
        Symbol::ZENUSDT,
        Symbol::GRIFFAINUSDT,
        Symbol::BNBUSDT,
        Symbol::RAYDIUMUSDT,
        Symbol::OMUSDT,
    ];

    public function __invoke(CheckPositionIsInLoss $message): void
    {
        /** @var $positions array<Position[]> */
        $positions = $this->positionService->getAllPositions();
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        foreach ($positions as $symbolPositions) {
            $mainPosition = ($first = $symbolPositions[array_key_first($symbolPositions)])->getHedge()?->mainPosition ?? $first;
            $symbol = $mainPosition->symbol;
            if (in_array($symbol, self::SUPPRESS, true)) {
                continue;
            }

            if (!$this->positionInLossAlertThrottlingLimiter->create($symbol->value)->consume()->isAccepted()) {
                continue;
            }

            if ($mainPosition->isPositionInLoss($lastMarkPrices[$symbol->value])) {
                $this->appErrorLogger->error(sprintf('%s is in loss', $mainPosition->getCaption()));
            }
        }
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly LoggerInterface $appErrorLogger,
        private readonly RateLimiterFactory $positionInLossAlertThrottlingLimiter,
    ) {
    }
}
