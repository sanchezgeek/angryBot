<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\BuyOrder;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Trading\Application\Symbol\SymbolProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Bot\Handler\ButOrder\CheckOrdersIsActiveHandlerTest
 */
#[AsMessageHandler]
final readonly class CheckOrdersNowIsActiveHandler
{
    public function __construct(
        private BuyOrderRepository $buyOrderRepository,
        private ExchangeServiceInterface $exchangeService,
        private EntityManagerInterface $entityManager,
        private SymbolProvider $symbolProvider,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckOrdersNowIsActive $message): void
    {
        $activeConditionalStopOrders = $this->exchangeService->activeConditionalOrders();

        /** @var array<array{symbol: string, items: BuyOrder[]}> $map */
        $map = [];

        $notExecutedOrdersSymbols = $this->buyOrderRepository->getNotExecutedOrdersSymbolsMap();
        foreach ($notExecutedOrdersSymbols as $symbolRaw => $positionSides) {
            $symbol = $this->symbolProvider->getOneByName($symbolRaw);
            $ticker = $this->exchangeService->ticker($symbol);

            foreach ($positionSides as $positionSide) {
                $symbolPrice = $ticker->indexPrice; /** @see BuyOrder::mustBeExecuted */

                $orders = $this->buyOrderRepository->getOrdersAfterPrice($symbol, $positionSide, $symbolPrice->value());
                foreach ($orders as $buyOrder) {
                    if (
                        ($createdAfterStopExchangeOrderId = $buyOrder->getOnlyAfterExchangeOrderExecutedContext())
                        && isset($activeConditionalStopOrders[$createdAfterStopExchangeOrderId])
                    ) {
                        continue;
                    }

                    $comparator = self::getComparator($buyOrder->getPositionSide());
                    if ($comparator($buyOrder->getPrice(), $symbolPrice)) {
                        $buyOrder->setActive($this->clock->now());
                    }
                }
            }
        }

        $this->entityManager->flush();
    }

    private static function getComparator(Side $positionSide): callable
    {
        if ($positionSide === Side::Buy) {
            return static fn(float $buyOrderPrice, SymbolPrice $currentPrice) => $currentPrice->lessOrEquals($buyOrderPrice);
        }

        return static fn(float $buyOrderPrice, SymbolPrice $currentPrice) => $currentPrice->greaterOrEquals($buyOrderPrice);
    }
}
