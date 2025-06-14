<?php

declare(strict_types=1);

namespace App\Stop\Application\Handler\Command;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Domain\Position\ValueObject\Side;
use App\Stop\Application\Contract\Command\CreateOppositeStopsAfterBuy;
use App\Stop\Application\Contract\CreateOppositeStopsAfterBuyCommandHandlerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\Handler\CreateOppositeStopsAfterBuyCommandHandlerTest
 */
#[AsMessageHandler]
final readonly class CreateOppositeStopsAfterBuyCommandHandler implements CreateOppositeStopsAfterBuyCommandHandlerInterface
{
    public function __invoke(CreateOppositeStopsAfterBuy $command): void
    {
        $buyOrder = $this->buyOrderRepository->find($command->buyOrderId);

        $symbol = $buyOrder->getSymbol();
        $side = $buyOrder->getPositionSide();
        $volume = $buyOrder->getVolume();

        $position = $this->positionService->getPosition($symbol, $side);
        $ticker = $this->exchangeService->ticker($symbol);

        $context = [];
        if ($position->isSupportPosition()) {
            $context[Stop::CLOSE_BY_MARKET_CONTEXT] = true;
        }

        if ($specifiedStopDistance = $buyOrder->getOppositeOrderDistance()) {
            $triggerPrice = $side->isShort() ? $buyOrder->getPrice() + $specifiedStopDistance : $buyOrder->getPrice() - $specifiedStopDistance;
            $this->stopService->create($position->symbol, $side, $triggerPrice, $volume, null, $context);
            return;
        }

        $triggerPrice = null;

        // @todo | based on liquidation if position under hedge?
        $strategy = $this->getStopStrategy($position, $buyOrder, $ticker);

        $stopStrategy = $strategy['strategy'];
        $description = $strategy['description'];

        $basePrice = null;
        if ($stopStrategy === StopCreate::AFTER_FIRST_POSITION_STOP) {
            if ($firstPositionStop = $this->stopRepository->findFirstPositionStop($position)) {
                $basePrice = $firstPositionStop->getPrice();
            }
        } elseif ($stopStrategy === StopCreate::AFTER_FIRST_STOP_UNDER_POSITION || ($stopStrategy === StopCreate::ONLY_BIG_SL_AFTER_FIRST_STOP_UNDER_POSITION && $volume >= StopCreate::BIG_SL_VOLUME_STARTS_FROM)) {
            if ($firstStopUnderPosition = $this->stopRepository->findFirstStopUnderPosition($position)) {
                $basePrice = $firstStopUnderPosition->getPrice();
            } else {
                $stopStrategy = StopCreate::UNDER_POSITION;
            }
        }

        if ($stopStrategy === StopCreate::UNDER_POSITION || ($stopStrategy === StopCreate::ONLY_BIG_SL_UNDER_POSITION && $volume >= StopCreate::BIG_SL_VOLUME_STARTS_FROM)) {
            $positionPrice = \ceil($position->entryPrice);
            if ($ticker->isIndexAlreadyOverStop($side, $positionPrice)) {
                $basePrice = $side->isLong() ? $ticker->indexPrice->value() - 15 : $ticker->indexPrice->value() + 15;
            } else {
                $basePrice = $side->isLong() ? $positionPrice - 15 : $positionPrice + 15;
                $basePrice += random_int(-15, 15);
            }
        } elseif ($stopStrategy === StopCreate::SHORT_STOP) {
            $stopPriceDelta = 20 + random_int(1, 25);
            $triggerPrice = $side->isShort() ? $buyOrder->getPrice() + $stopPriceDelta : $buyOrder->getPrice() - $stopPriceDelta;
        }

        if ($basePrice) {
            if (!$ticker->isIndexAlreadyOverStop($side, $basePrice)) {
                $triggerPrice = $side === Side::Sell ? $basePrice + 1 : $basePrice - 1;
            } else {
                $description = 'because index price over stop)';
            }
        }

        // If still cannot get best $triggerPrice
        if ($stopStrategy !== StopCreate::DEFAULT && $triggerPrice === null) {
            $stopStrategy = StopCreate::DEFAULT;
        }

        if ($stopStrategy === StopCreate::DEFAULT) {
            $stopPriceDelta = StopCreate::getDefaultStrategyStopOrderDistance($volume);

            $triggerPrice = $side === Side::Sell ? $buyOrder->getPrice() + $stopPriceDelta : $buyOrder->getPrice() - $stopPriceDelta;
        }

        $this->stopService->create($position->symbol, $side, $triggerPrice, $volume, null, $context);
    }

    /**
     * @return array{strategy: StopCreate, description: string}
     */
    private function getStopStrategy(Position $position, BuyOrder $order, Ticker $ticker): array
    {
        if (($hedge = $position->getHedge()) && $hedge->isSupportPosition($position)) {
            $hedgeStrategy = $hedge->getHedgeStrategy();
            return [
                'strategy' => $hedgeStrategy->supportPositionStopCreation, // 'strategy' => $hedge->isSupportPosition($position) ? $hedgeStrategy->supportPositionStopCreation : $hedgeStrategy->mainPositionStopCreation,
                'description' => $hedgeStrategy->description,
            ];
        }

        $delta = $position->getDeltaWithTicker($ticker);

        // only if without hedge?
        // if (($delta < 0) && (abs($delta) >= $defaultStrategyStopPriceDelta)) {return ['strategy' => StopCreate::SHORT_STOP, 'description' => 'position in loss'];}

        if ($order->isWithShortStop()) {
            return ['strategy' => StopCreate::SHORT_STOP, 'description' => 'by $order->isWithShortStop() condition'];
        }

        // @todo Нужен какой-то определятор состояния трейда
        if ($delta >= 1500) {
            return ['strategy' => StopCreate::AFTER_FIRST_STOP_UNDER_POSITION, 'description' => sprintf('delta=%.2f -> increase position size', $delta)];
        }

        if ($delta >= 500) {
            return ['strategy' => StopCreate::UNDER_POSITION, 'description' => sprintf('delta=%.2f -> to reduce added by mistake on start', $delta)];
        }

        $defaultStrategyStopPriceDelta = StopCreate::getDefaultStrategyStopOrderDistance($order->getVolume());

        // To not reduce position size by placing stop orders between position and ticker
        if ($delta > (2 * $defaultStrategyStopPriceDelta)) {
            return ['strategy' => StopCreate::UNDER_POSITION, 'description' => sprintf('delta=%.2f -> keep position size on start', $delta)];
        }

        if ($delta > $defaultStrategyStopPriceDelta) {
            return ['strategy' => StopCreate::UNDER_POSITION, 'description' => sprintf('delta=%.2f -> keep position size on start', $delta)];
        }

        return ['strategy' => StopCreate::DEFAULT, 'description' => 'by default'];
    }

    public function __construct(
        private StopService $stopService,
        private BuyOrderRepository $buyOrderRepository,
        private StopRepository $stopRepository,
        private PositionServiceInterface $positionService,
        private ExchangeServiceInterface $exchangeService,
    ) {
    }
}
