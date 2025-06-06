<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\BuyOrder;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckOrdersNowIsActiveHandler
{
    private const int ACTIVE_STATE_TTL = 20000;

    public function __construct(
        private BuyOrderRepository $buyOrderRepository,
        private ExchangeServiceInterface $exchangeService,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckOrdersNowIsActive $message): void
    {
        $allActiveOrders = $this->buyOrderRepository->getAllActiveOrders();
        $now = $this->clock->now();
        foreach ($allActiveOrders as $buyOrder) {
            if (!$activeStateSetAt = $buyOrder->getActiveStateChangeTimestamp()) {
                continue;
            }

            if ($now->getTimestamp() - $activeStateSetAt > self::ACTIVE_STATE_TTL) {
                $buyOrder->setIdle();
            }
        }
        $this->entityManager->flush();

        $map = [];
        $allIdleOrders = $this->buyOrderRepository->getAllIdleOrders();
        foreach ($allIdleOrders as $buyOrder) {
            $symbol = $buyOrder->getSymbol();
            $symbolName = $symbol->name();

            $map[$symbolName] = $map[$symbolName] ?? ['symbol' => $symbol, 'items' => []];
            $map[$symbolName]['items'][] = $buyOrder;
        }

        foreach ($map as $symbolItems) {
            $symbol = $symbolItems['symbol'];
            $ticker = $this->exchangeService->ticker($symbol);

            foreach ($symbolItems['items'] as $buyOrder) {
                /** @var BuyOrder $buyOrder */
                $comparator = self::getComparator($buyOrder->getPositionSide());
                if ($comparator($buyOrder->getPrice(), $ticker->indexPrice)) {
                    $buyOrder->setActive($this->clock->now());
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
