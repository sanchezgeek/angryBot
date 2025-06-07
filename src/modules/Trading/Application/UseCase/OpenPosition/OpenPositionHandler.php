<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderEntryDto;
use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\CannotAffordOrderCostException;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Order\OrderType;
use App\Domain\Order\Collection\OrdersCollection;
use App\Domain\Order\Collection\OrdersLimitedWithMaxVolume;
use App\Domain\Order\Collection\OrdersWithMinExchangeVolume;
use App\Domain\Order\OrdersGrid;
use App\Domain\Price\Exception\PriceCannotBeLessThanZero;
use App\Helper\FloatHelper;
use App\Helper\OutputHelper;
use App\Trading\Application\Order\ContextShortcut\ContextShortcutRootProcessor;
use App\Trading\Application\Order\ContextShortcut\Exception\UnapplicableContextShortcutProcessorException;
use App\Trading\Application\UseCase\OpenPosition\Exception\AutoReopenPositionDenied;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use RuntimeException;

final class OpenPositionHandler
{
    private OpenPositionEntryDto $entryDto;
    private Ticker $ticker;
    private ?Position $currentlyOpenedPosition;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeAccountServiceInterface $accountService,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly OrderServiceInterface $tradeService,
        private readonly CreateBuyOrderHandler $createBuyOrderHandler,
        private readonly StopService $stopService,
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly PositionServiceInterface $positionService,
        private readonly ContextShortcutRootProcessor $contextShortcutRootProcessor
    ) {
    }

    /**
     * @throws CannotAffordOrderCostException
     * @throws AutoReopenPositionDenied
     * @throws Exception
     */
    public function handle(OpenPositionEntryDto $entryDto): void
    {
        $this->entryDto = $entryDto;
        $symbol = $this->entryDto->symbol;
        $positionSide = $entryDto->positionSide;

        $this->ticker = $this->exchangeService->ticker($symbol);
        $this->currentlyOpenedPosition = $this->positionService->getPosition($symbol, $positionSide);

        $totalSize = $this->getTotalSize();

        // @todo | reopen | remove all BO left from previous position? (without `uniq` or just bigger than 0.005)
        if ($entryDto->closeAndReopenCurrentPosition) {
            $this->closeCurrentPosition();
        }

        if ($this->isDryRun()) {
            $this->output('Debug enabled. Terminate.');
            return;
        }

        $existedStops = $this->stopRepository->findActive($symbol, $positionSide); // @todo | or findAll?
        $removeStops = $existedStops && ($entryDto->removeExistedStops || $this->currentlyOpenedPosition === null); // position not opened => force remove

# begin position open
        $this->entityManager->beginTransaction();

        if ($removeStops) {
            foreach ($existedStops as $stop) $this->entityManager->remove($stop);
            $this->entityManager->flush();
        }

        $buyGridOrdersVolumeSum = 0;
        $buyOrders = $entryDto->buyGridsDefinition === null ? [] : $this->createBuyOrdersGrid($this->entryDto->buyGridsDefinition, $totalSize);
        foreach ($buyOrders as $buyOrder) {
            $buyGridOrdersVolumeSum += $buyOrder->getVolume();
        }

# do market buy
        $marketBuyVolume = $symbol->roundVolume($totalSize - $buyGridOrdersVolumeSum); // $marketBuyPart = Percent::fromString('100%')->sub($gridPart); $marketBuyVolume = $marketBuyPart->of($size);

        if ($entryDto->stopsGridsDefinition) {
            $this->createStopsGrid($entryDto->stopsGridsDefinition, $marketBuyVolume);
        }

        $this->tradeService->marketBuy($symbol, $positionSide, $marketBuyVolume);

        $this->entityManager->flush();
        $this->entityManager->commit();

        // @todo | check on prod that position opened with desired size (vs testnet)
        $resultPosition = $this->positionService->getPosition($symbol, $positionSide);
        if (!$resultPosition) {
            throw new RuntimeException(sprintf('[%s] Something went wrong: position not found after marketBuy', OutputHelper::shortClassName($this)));
        }

        foreach ($buyOrders as $buyOrder) {
            if (!$buyOrder->getOppositeOrderDistance()) {
                $buyOrder->setIsWithoutOppositeOrder(); // only if opposite distance was not provided while orders creation
            }

            if ($resultPosition->isSupportPosition()) {
                $buyOrder->isForceBuyOrder(); // @todo | open-position | research
            }
            $this->buyOrderRepository->save($buyOrder);
        }
    }

    /**
     * @throws AutoReopenPositionDenied
     */
    private function closeCurrentPosition(): void
    {
        if (!$position = $this->currentlyOpenedPosition) {
            // @todo only for console
            $this->output('Position not found. Skip closeCurrentPosition.');
            return;
        }

        $unrealizedPnl = $position->unrealizedPnl;
        if ($unrealizedPnl >= 0) {
            throw new AutoReopenPositionDenied(sprintf('Current unrealized PNL: %.3f. Reopen denied.', $unrealizedPnl));
        }

        if ($this->isDryRun()) {
            return;
        }

        $this->tradeService->closeByMarket($position, $position->size);

        $symbol = $this->entryDto->symbol;
        $currentLoss = $symbol->associatedCoinAmount(-$unrealizedPnl);
        try {
            $spotBalance = $this->accountService->getSpotWalletBalance($symbol->associatedCoin());
            if ($spotBalance->available() > 2) {
                $this->accountService->interTransferFromSpotToContract(
                    $symbol->associatedCoin(),
                    min(FloatHelper::round($spotBalance->available() - 1, 2), $currentLoss->value()),
                );
            }
        } catch (Exception $e) {
            $this->output(sprintf('Gto "%s" while transfer from stop after loss', $e->getMessage()));
        }
    }

    /**
     * @return BuyOrder[]
     *
     * @throws PriceCannotBeLessThanZero
     * @throws UnapplicableContextShortcutProcessorException
     */
    private function createBuyOrdersGrid(OrdersGridDefinitionCollection $ordersGridDefinitionsCollection, float $totalSize): array
    {
        $symbol = $this->entryDto->symbol;
        $positionSide = $this->entryDto->positionSide;

        $result = [];
        foreach ($ordersGridDefinitionsCollection as $ordersGridDefinition) {
            $forVolume = $ordersGridDefinition->definedPercent->of($totalSize);
            $randStep = $symbol->minimalPriceMove() * 10;

            $orders = new OrdersLimitedWithMaxVolume(
                new OrdersWithMinExchangeVolume($symbol, new OrdersCollection(
                    ...new OrdersGrid($ordersGridDefinition->priceRange)->ordersByQnt($forVolume, $ordersGridDefinition->ordersCount)
                )),
                $forVolume
            );

            foreach ($orders as $order) {
                $rand = FloatHelper::modify($randStep, 0.3);
                $orderPrice = $order->price()->add($rand);

                $buyOrder = $this->createBuyOrderHandler->handle(new CreateBuyOrderEntryDto($symbol, $positionSide, $order->volume(), $orderPrice->value()))->buyOrder;
                $this->contextShortcutRootProcessor->modifyOrderWithShortcuts($ordersGridDefinition->contextsDefs, $buyOrder);

                $result[] = $buyOrder;
            }
        }

        return $result;
    }

    private function createStopsGrid(OrdersGridDefinitionCollection $ordersGridDefinitionsCollection, float $totalSize): void
    {
        $symbol = $this->entryDto->symbol;
        $positionSide = $this->entryDto->positionSide;

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
                $this->stopService->create($symbol, $positionSide, $order->price()->value(), $order->volume(), null, $stopsContext);
                $resultTotalVolume += $order->volume();
            }
        }
    }

    /**
     * @throws Exception
     */
    private function getTotalSize(): float
    {
        $contractBalance = $this->accountService->getContractWalletBalance($this->entryDto->symbol->associatedCoin());
        if ($contractBalance->available() <= 0) {
            throw new Exception('Insufficient available contract balance');
        }

        // @todo use leverage
        $contractCost = $this->ticker->indexPrice->value() / 100 * (1 + 0.1);

        return $this->entryDto->percentOfDepositToRisk->of($contractBalance->available() / $contractCost);
    }

    private function isDryRun(): bool
    {
        return $this->entryDto->dryRun;
    }

    private function output(string $message): void
    {
        if ($this->entryDto->outputEnabled) {
            OutputHelper::print($message);
        }
    }
}
