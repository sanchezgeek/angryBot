<?php

declare(strict_types=1);

namespace App\Application\EventListener\Stop;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Domain\Stop\Event\StopPushedToExchange;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class FixMainHedgePositionListener
{
    public const ENABLED = false;

    const APPLY_IF_SUPPORT_RATE_LESS_THAN = 40;
    const APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN = 180;
    const SUPPLY_STOP_VOLUME = 0.001;

    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        private readonly StopService $stopService,
    ) {
    }

    public function __invoke(StopPushedToExchange $event): void
    {
        if (!self::ENABLED) {
            return;
        }

        $stop = $event->stop;
        $symbol = $event->symbol;

        # only for stops closed by market
        if (!$stop->isCloseByMarketContextSet()) {
            return;
        }

        if (!($stop->getVolume() > 0.005)) {
            return;
        }

        $position = $this->positionService->getPosition($symbol, $stop->getPositionSide());
        $ticker = $this->exchangeService->ticker($symbol);

        if (
            ($hedge = $position->getHedge())
            && $hedge->isSupportPosition($position) && $hedge->getSupportRate()->value() < self::APPLY_IF_SUPPORT_RATE_LESS_THAN
            && ($mainPositionPnlPercent = $ticker->lastPrice->getPnlPercentFor($hedge->mainPosition))
            && $mainPositionPnlPercent > self::APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN
        ) {
            $context = [Stop::CLOSE_BY_MARKET_CONTEXT => true];
            $supplyStopPrice = $position->isLong() ? $stop->getPrice() + 50 : $stop->getPrice() - 50;

            $this->stopService->create($hedge->mainPosition->side, $supplyStopPrice, self::SUPPLY_STOP_VOLUME, 10, $context);
        }
    }
}