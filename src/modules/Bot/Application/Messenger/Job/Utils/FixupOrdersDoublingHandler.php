<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Order\OrderType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FixupOrdersDoublingHandler
{
    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly BuyOrderRepository $buyOrderRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function __invoke(FixupOrdersDoubling $message)
    {
        $repository = $message->orderType === OrderType::Add
            ? $this->buyOrderRepository
            : $this->stopRepository;

        $orders = $repository->findActive(
            side: $message->positionSide,
//            exceptOppositeOrders: true,
            qbModifier: static fn (QueryBuilder $qb) => $qb->addOrderBy(new OrderBy($qb->getRootAliases()[0] . '.price', 'desc'))
        );

        if (!$orders) {
            return;
        }

        $forRemove = [];

        /** @var Stop[]|BuyOrder[] $stepOrders */
        $stepOrders = [];
        $stepBottom = ceil($orders[0]->getPrice()) - $message->step;
        while ($order = \array_shift($orders)) {
            /** @var Stop|BuyOrder $order */
            if ($order->getPrice() < $stepBottom || !count($orders)) {
                $stepBottom = $stepBottom - $message->step;
                if (\count($orders)) {
                    \array_unshift($orders, $order); // Push back in case of further iteration
                }
                \usort($stepOrders, static fn(Stop|BuyOrder $a, Stop|BuyOrder $b) => $a->getVolume() <=> $b->getVolume());

                $removedVolume = 0;
                while (count($stepOrders) > $message->maxStepOrdersQnt) {
                    $forRemove[] = ($removed = \array_shift($stepOrders));
                    $removedVolume += $removed->getVolume();
                }

                // Add volume to first in left group
                if ($removedVolume && $message->groupInOne) {
                    $firstInGroup = \array_shift($stepOrders);
                    $firstInGroup->addVolume($removedVolume);

                    $this->entityManager->persist($firstInGroup);
                    $this->entityManager->flush();
                }

                $stepOrders = [];
            } else {
                $stepOrders[] = $order;
            }
        }

        foreach ($forRemove as $order) {
            $this->entityManager->remove($order);
        }

        $this->entityManager->flush();
    }
}
