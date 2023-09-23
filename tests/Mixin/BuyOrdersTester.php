<?php

declare(strict_types=1);

namespace App\Tests\Mixin;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;

use function usort;

trait BuyOrdersTester
{
    use TestWithDoctrineRepository;
    use PositionSideAwareTest;

    /**
     * @return BuyOrder[]
     */
    protected static function getCurrentBuyOrdersSnapshot(): array
    {
        $buyOrders = [];
        foreach(self::getBuyOrderRepository()->findAll() as $buyOrder) {
            $buyOrders[] = clone $buyOrder;
        }

        return $buyOrders;
    }

    protected static function seeBuyOrdersInDb(BuyOrder ...$expectedBuyOrders): void
    {
        $actualBuyOrders = self::getBuyOrderRepository()->findAll();

        usort($expectedBuyOrders, static fn (BuyOrder $a, BuyOrder $b) => $a->getId() <=> $b->getId());
        usort($actualBuyOrders, static fn (BuyOrder $a, BuyOrder $b) => $a->getId() <=> $b->getId());

        self::assertEquals($expectedBuyOrders, $actualBuyOrders);
    }

    protected static function getBuyOrderRepository(): BuyOrderRepository
    {
        return self::getContainer()->get(BuyOrderRepository::class);
    }

    protected static function truncateBuyOrders(): int
    {
        $qnt = self::truncate(BuyOrder::class);

        $entityManager = self::getEntityManager();
        $entityManager->getConnection()->executeQuery('SELECT setval(\'buy_order_id_seq\', 1, false);');

        return $qnt;
    }
}
