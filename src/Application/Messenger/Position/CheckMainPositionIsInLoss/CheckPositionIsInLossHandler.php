<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\CheckMainPositionIsInLoss;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
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
        Symbol::ADAUSDT,
        Symbol::FARTCOINUSDT,
        Symbol::GRIFFAINUSDT,
        Symbol::BNBUSDT,
    ];

    public function __invoke(CheckPositionIsInLoss $message): void
    {
        $symbol = $message->symbol;

        if (in_array($symbol, self::SUPPRESS, true)) {
            return;
        }

        if (!$this->positionInLossAlertThrottlingLimiter->create($symbol->value)->consume()->isAccepted()) {
            return;
        }

        if (!($position = $this->getPosition($symbol))) {
            return;
        }

        if (!$position->isPositionInLoss($this->exchangeService->ticker($symbol)->markPrice)) {
            return;
        }

        $this->appErrorLogger->error(sprintf('%s is in loss', $position->getCaption()));
    }

    private function getPosition(Symbol $symbol): ?Position
    {
        if (!($positions = $this->positionService->getPositions($symbol))) {
            return null;
        }

        return $positions[0]->getHedge()?->mainPosition ?? $positions[0];
    }

    public function __construct(
        private readonly PositionServiceInterface $positionService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly LoggerInterface $appErrorLogger,
        private readonly RateLimiterFactory $positionInLossAlertThrottlingLimiter,
    ) {
    }
}
