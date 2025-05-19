<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\BuyOrder;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckOrdersNowIsActiveHandler
{
    public function __construct(
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly PositionServiceInterface $positionService,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function __invoke(CheckOrdersNowIsActive $message)
    {
        /** @var $positions array<Position[]> */
        $positions = $this->positionService->getAllPositions();
        /** @var $lastMarkPrices array<string, SymbolPrice> */
        $lastMarkPrices = $this->positionService->getLastMarkPrices();

        $symbols = [];
        foreach ($positions as $symbolRaw => $symbolPositions) {
            $symbols[] = Symbol::from($symbolRaw);
        }
        $idleOrders = $this->buyOrderRepository->getIdleOrders(...$symbols);

        foreach ($positions as $symbolRaw => $symbolPositions) {
            $symbol = Symbol::from($symbolRaw);
            $idlePositionOrders = array_filter($idleOrders, static fn(BuyOrder $order) => $order->getSymbol() === $symbol);

            foreach ($idlePositionOrders as $buyOrder) {
                $comparator = self::getComparator($buyOrder->getPositionSide());
                if ($comparator($buyOrder->getPrice(), $lastMarkPrices[$symbolRaw])) {
                    $buyOrder->setActive();
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
