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
use App\Worker\AppContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\ApplyStopsGrid\ApplyStopsToPositionHandlerTest
 */
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
            $ordersCount = $ordersGridDefinition->ordersCount;
            $priceRange = $ordersGridDefinition->priceRange;

            $stopsContext = array_merge(
                $entryDto->additionalContext,
                $this->contextShortcutRootProcessor->getResultContextArray($ordersGridDefinition->contextsDefs, OrderType::Stop)
            );

            $orders = new OrdersGrid($priceRange)->ordersByQnt($forVolume, $ordersCount);
            $orders = new OrdersCollection(...$orders);

//            $stopsHasOppositeBuyOrders = ($stopsContext[Stop::WITHOUT_OPPOSITE_ORDER_CONTEXT] ?? false) === false; /** @todo | Context =( */ $roundVolumeToMin = $stopsHasOppositeBuyOrders;
            $roundVolumeToMin = true;
            if ($roundVolumeToMin) {
                $orders = new OrdersWithMinExchangeVolume($symbol, $orders);
            }

            foreach (new OrdersLimitedWithMaxVolume($orders, $forVolume, $symbol, $positionSide) as $order) {
                if ($resultTotalVolume >= $totalSize) {
                    break;
                }

                $stops[] = $this->stopService->create(
                    $symbol,
                    $positionSide,
                    $order->price()->value(),
                    $order->volume(),
                    null,
                    $stopsContext,
                );

                $resultTotalVolume += $order->volume();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->commit();

        return new StopsCollection(...$stops);
    }
}
