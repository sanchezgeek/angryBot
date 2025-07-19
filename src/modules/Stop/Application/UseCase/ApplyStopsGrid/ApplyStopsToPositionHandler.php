<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\ApplyStopsGrid;

use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\OrdersGrid;
use App\Domain\Stop\StopsCollection;
use App\Trading\Application\Order\ContextShortcut\ContextShortcutRootProcessor;
use App\Trading\Application\Order\ContextShortcut\Exception\UnapplicableContextShortcutProcessorException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ApplyStopsToPositionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StopService $stopService,
        private ContextShortcutRootProcessor $contextShortcutRootProcessor
    ) {
    }

    /**
     * @return StopsCollection Created stops
     *
     * @throws UnapplicableContextShortcutProcessorException
     */
    public function handle(ApplyStopsToPositionEntryDto $entryDto): StopsCollection
    {
        $symbol = $entryDto->symbol;
        $positionSide = $entryDto->positionSide;
        $ordersGridDefinitionsCollection = $entryDto->stopsGridsDefinition;
        $totalSize = $entryDto->totalSize;

        $this->entityManager->beginTransaction();

        $stops = [];
        $resultTotalVolume = 0;
        foreach ($ordersGridDefinitionsCollection as $ordersGridDefinition) {
            $forVolume = $ordersGridDefinition->definedPercent->of($totalSize);
            $stopsContext = $this->contextShortcutRootProcessor->getResultContextArray($ordersGridDefinition->contextsDefs, OrderType::Stop);
            $stopsHasOppositeBuyOrders = ($stopsContext[Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT] ?? false) === false; /** @todo | Context =( */

            $orders = new OrdersGrid($ordersGridDefinition->priceRange)->ordersByQnt($forVolume, $ordersGridDefinition->ordersCount);
            $orders = new OrdersCollection(...$orders);

            $roundVolumeToMin = $stopsHasOppositeBuyOrders;
            if ($roundVolumeToMin) {
                $orders = new OrdersWithMinExchangeVolume($symbol, $orders);
            }

            foreach (new OrdersLimitedWithMaxVolume($orders, $forVolume) as $order) {
                if ($resultTotalVolume >= $totalSize) {
                    break;
                }

                // @todo | open-position | add triggerDelta?
                $stops[] = $this->stopService->create($symbol, $positionSide, $order->price()->value(), $order->volume(), null, $stopsContext);
                $resultTotalVolume += $order->volume();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->commit();

        return new StopsCollection(...$stops);
    }
}
