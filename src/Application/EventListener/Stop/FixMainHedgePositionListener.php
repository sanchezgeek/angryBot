<?php

declare(strict_types=1);

namespace App\Application\EventListener\Stop;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Domain\Order\ExchangeOrder;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Stop\Event\StopPushedToExchange;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

use function random_int;
use function sprintf;
use function var_dump;

/**
 * `stop.pushedToExchange` [
 *      1) after supportPosition stop loss
 *      2) for stops closed by market
 *      3) withOppositeOrder
 * ] => cover losses by adding SL on MainPosition.
 */
#[AsEventListener]
final class FixMainHedgePositionListener
{
    public const ENABLED = false;

    const APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN = 180;
    const APPLY_IF_STOP_VOLUME_GREATER_THAN = 0.001;

    const SUPPLY_STOP_VOLUME = 0.001;
    const SUPPLY_STOP_DISTANCE = 500;

    public function __construct(
        private readonly OrderCostCalculator $orderCostCalculator,
        private readonly ExchangeAccountServiceInterface $exchangeAccountService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
        private readonly HedgeService $hedgeService,
        private readonly StopService $stopService,
    ) {
    }

    public function __invoke(StopPushedToExchange $event): void
    {
        if (!self::ENABLED) {
            return;
        }

        $symbol = $event->symbol;

        $stop = $event->stop;
        $stopPrice = $stop->getPrice();
        $closedVolume = $stop->getVolume();

        if (!($stop->isCloseByMarketContextSet() && $stop->isWithOppositeOrder())) {
            return;
        }

        if (!($closedVolume >= self::APPLY_IF_STOP_VOLUME_GREATER_THAN)) {
            return;
        }

        $stoppedPosition = $this->positionService->getPosition($symbol, $stop->getPositionSide());

        $hedge = $stoppedPosition->getHedge();
        if (!($hedge && $hedge->isSupportPosition($stoppedPosition))) {
            return;
        }

        if ($this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge)) {
            var_dump(sprintf('%s size enough for support mainPosition => skip', $stoppedPosition->getCaption()));
            return;
        }

        var_dump(sprintf('FixMainHedgePositionListener: support stop closed at %f', $stopPrice));

        $ticker = $this->exchangeService->ticker($symbol);
        $mainPositionPnlPercent = $ticker->lastPrice->getPnlPercentFor($hedge->mainPosition);
        if ($mainPositionPnlPercent < self::APPLY_IF_MAIN_POSITION_PNL_GREATER_THAN) {
            var_dump('mainPosition PNL is not enough for add supply stop => skip');
            return;
        }

        $contractBalance = $this->exchangeAccountService->getContractWalletBalance($symbol->associatedCoin());
        $orderCost = $this->orderCostCalculator->totalBuyCost(new ExchangeOrder($symbol, $closedVolume, $ticker->lastPrice), $stoppedPosition->leverage, $stoppedPosition->side)->value();

        if ($contractBalance->available() > $orderCost) {
            var_dump(sprintf('CONTRACT.availableBalance > %f (orderCost) => skip', $orderCost));
            return;
        }

        $context = [Stop::CLOSE_BY_MARKET_CONTEXT => true, Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT => true];
        $distance = self::SUPPLY_STOP_DISTANCE + random_int(-150, 30);
        $supplyStopPrice = $stoppedPosition->isLong() ? $stopPrice + $distance : $stopPrice - $distance;
        $this->stopService->create($hedge->mainPosition->symbol, $hedge->mainPosition->side, $supplyStopPrice, self::SUPPLY_STOP_VOLUME, PushBuyOrdersHandler::STOP_ORDER_TRIGGER_DELTA, $context);

        var_dump('supply stop created');
    }
}