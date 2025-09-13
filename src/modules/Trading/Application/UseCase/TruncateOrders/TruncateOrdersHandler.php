<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\TruncateOrders;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Domain\BuyOrder\BuyOrdersCollection;
use App\Domain\Stop\StopsCollection;
use App\Trading\Application\UseCase\TruncateOrders\Enum\TruncateOrdersMode;
use App\Trading\Application\UseCase\TruncateOrders\Enum\TruncateOrdersType;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class TruncateOrdersHandler
{
    public function __construct(
        private BuyOrderRepository $buyOrderRepository,
        private StopRepositoryInterface $stopRepository,
        private EntityManagerInterface $entityManager
    ) {
    }

    public function __invoke(TruncateOrdersEntry $entry): TruncateOrdersResult
    {
        $dryRun = $entry->dry;
        $filterCallbacks = $entry->getBuyOrdersFilterCallbacks();
        $mode = $entry->mode;
        $ordersType = $entry->ordersType;

        $totalStopsCount = null;
        $removedStopsCount = null;
        $totalBuyOrdersCount = null;
        $removedBuyOrdersCount = null;

        if ($mode === TruncateOrdersMode::All && !$filterCallbacks) {
            $connection = $this->entityManager->getConnection();

            if ($ordersType === TruncateOrdersType::All) {
                if ($dryRun) {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery('SELECT count(1) FROM buy_order WHERE 1=1')->fetchOne();
                    $totalStopsCount = $removedStopsCount = $connection->executeQuery('SELECT count(1) FROM stop WHERE 1=1')->fetchOne();
                } else {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery('DELETE FROM buy_order WHERE 1=1')->rowCount();
                    $connection->executeQuery('SELECT setval(\'buy_order_id_seq\', 1, false);');

                    $totalStopsCount = $removedStopsCount = $connection->executeQuery('DELETE FROM stop WHERE 1=1')->rowCount();
                    $connection->executeQuery('SELECT setval(\'stop_id_seq\', 1, false);');
                }
            } elseif ($ordersType === TruncateOrdersType::Buy) {
                if ($dryRun) {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery('SELECT count(1) FROM buy_order WHERE 1=1')->fetchOne();
                } else {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery('DELETE FROM buy_order WHERE 1=1')->rowCount();
                    $connection->executeQuery('SELECT setval(\'buy_order_id_seq\', 1, false);');
                }
            } elseif ($ordersType === TruncateOrdersType::Stops) {
                if ($dryRun) {
                    $totalStopsCount = $removedStopsCount = $connection->executeQuery('SELECT count(1) FROM stop WHERE 1=1')->fetchOne();
                } else {
                    $totalStopsCount = $removedStopsCount = $connection->executeQuery('DELETE FROM stop WHERE 1=1')->rowCount();
                    $connection->executeQuery('SELECT setval(\'stop_id_seq\', 1, false);');
                }
            }

            return new TruncateOrdersResult(
                totalStopsCount: $totalStopsCount,
                removedStopsCount: $removedStopsCount,
                totalBuyOrdersCount: $totalBuyOrdersCount,
                removedBuyOrdersCount: $removedBuyOrdersCount
            );
        }

        $stops = null;
        $buyOrders = null;

        if ($mode === TruncateOrdersMode::All) {
            if ($ordersType === TruncateOrdersType::All) {
                $buyOrders = $this->buyOrderRepository->findAll();
                $stops = $this->stopRepository->findAll();
            } elseif ($ordersType === TruncateOrdersType::Buy) {
                $buyOrders = $this->buyOrderRepository->findAll();
            } elseif ($ordersType === TruncateOrdersType::Stops) {
                $stops = $this->stopRepository->findAll();
            }
        } elseif ($mode === TruncateOrdersMode::Active) {
            if ($ordersType === TruncateOrdersType::All) {
                $buyOrders = $this->buyOrderRepository->findActive();
                $stops = $this->stopRepository->findActive();
            } elseif ($ordersType === TruncateOrdersType::Buy) {
                $buyOrders = $this->buyOrderRepository->findActive();
            } elseif ($ordersType === TruncateOrdersType::Stops) {
                $stops = $this->stopRepository->findActive();
            }
        }

        $this->entityManager->beginTransaction();

        $errors = [];

        if ($stops) {
            $stops = new StopsCollection(...$stops);
            $totalStopsCount = $stops->totalCount();

            if ($filterCallbacks) {
                $stops = $stops->filterWithCallback(static function (Stop $stop) use ($filterCallbacks, &$errors) {
                    foreach ($filterCallbacks as $filterCallback) {
                        try {
                            eval('$callbackResult = $stop->' . $filterCallback . ';');
                            if ($callbackResult !== true) {
                                return false;
                            }
                        } catch (Throwable $e) {
                            $errors[] = $e->getMessage();
                        }
                    }

                    return true;
                });
            }

            $removedStopsCount = $stops->totalCount();

            foreach ($stops as $stop) {
                $this->entityManager->remove($stop);
            }
        }

        if ($buyOrders) {
            $buyOrders = new BuyOrdersCollection(...$buyOrders);
            $totalBuyOrdersCount = $buyOrders->totalCount();

            if ($filterCallbacks) {
                $buyOrders = $buyOrders->filterWithCallback(static function (BuyOrder $buyOrder) use ($filterCallbacks, &$errors) {
                    foreach ($filterCallbacks as $filterCallback) {
                        try {
                            eval('$callbackResult = $buyOrder->' . $filterCallback . ';');
                            if ($callbackResult !== true) {
                                return false;
                            }
                        } catch (Throwable $e) {
                            $errors[] = $e->getMessage();
                        }
                    }

                    return true;
                });
            }

            $removedBuyOrdersCount = $buyOrders->totalCount();

            foreach ($buyOrders as $buyOrder) {
                $this->entityManager->remove($buyOrder);
            }
        }

        if ($dryRun) {
            $this->entityManager->rollback();
        } else {
            $this->entityManager->flush();
            $this->entityManager->commit();
        }

        $errors = array_unique($errors);

        return new TruncateOrdersResult(
            totalStopsCount: $totalStopsCount,
            removedStopsCount: $removedStopsCount,
            totalBuyOrdersCount: $totalBuyOrdersCount,
            removedBuyOrdersCount: $removedBuyOrdersCount,
            errors: $errors
        );
    }
}
