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
        $buyOrdersFilterCallbacks = $entry->getBuyOrdersFilterCallbacks();
        $slFilterCallbacks = $entry->getStopsFilterCallbacks();

        $all = $entry->mode === TruncateOrdersMode::All;
        $ordersType = $entry->ordersType;

        $totalStopsCount = null;
        $removedStopsCount = null;
        $totalBuyOrdersCount = null;
        $removedBuyOrdersCount = null;

        $symbol = $entry->symbol;
        $whereClause = $symbol ? "symbol='$symbol'" : '1=1';

        if ($all && !$buyOrdersFilterCallbacks && !$slFilterCallbacks) {
            $connection = $this->entityManager->getConnection();

            if ($ordersType === TruncateOrdersType::All) {
                if ($dryRun) {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery(sprintf('SELECT count(1) FROM buy_order WHERE %s', $whereClause))->fetchOne();
                    $totalStopsCount = $removedStopsCount = $connection->executeQuery(sprintf('SELECT count(1) FROM stop WHERE %s', $whereClause))->fetchOne();
                } else {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery(sprintf('DELETE FROM buy_order WHERE %s', $whereClause))->rowCount();
//                    if (!$symbol) {
//                        $connection->executeQuery('SELECT setval(\'buy_order_id_seq\', 1, false);');
//                    }

                    $totalStopsCount = $removedStopsCount = $connection->executeQuery(sprintf('DELETE FROM stop WHERE %s', $whereClause))->rowCount();
//                    if (!$symbol) {
//                        $connection->executeQuery('SELECT setval(\'stop_id_seq\', 1, false);');
//                    }
                }
            } elseif ($ordersType === TruncateOrdersType::Buy) {
                if ($dryRun) {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery(sprintf('SELECT count(1) FROM buy_order WHERE %s', $whereClause))->fetchOne();
                } else {
                    $totalBuyOrdersCount = $removedBuyOrdersCount = $connection->executeQuery(sprintf('DELETE FROM buy_order WHERE %s', $whereClause))->rowCount();
//                    if (!$symbol) {
//                        $connection->executeQuery('SELECT setval(\'buy_order_id_seq\', 1, false);');
//                    }
                }
            } elseif ($ordersType === TruncateOrdersType::Stops) {
                if ($dryRun) {
                    $totalStopsCount = $removedStopsCount = $connection->executeQuery(sprintf('SELECT count(1) FROM stop WHERE %s', $whereClause))->fetchOne();
                } else {
                    $totalStopsCount = $removedStopsCount = $connection->executeQuery(sprintf('DELETE FROM stop WHERE %s', $whereClause))->rowCount();
//                    if (!$symbol) {
//                        $connection->executeQuery('SELECT setval(\'stop_id_seq\', 1, false);');
//                    }
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

        if ($ordersType === TruncateOrdersType::All) {
            $buyOrders = $all ? $this->buyOrderRepository->findAllByParams($symbol) : $this->buyOrderRepository->findActive($symbol);
            $stops = $all ? $this->stopRepository->findAllByParams($symbol) : $this->stopRepository->findActive($symbol);
        } elseif ($ordersType === TruncateOrdersType::Buy) {
            $buyOrders = $all ? $this->buyOrderRepository->findAllByParams($symbol) : $this->buyOrderRepository->findActive($symbol);
        } elseif ($ordersType === TruncateOrdersType::Stops) {
            $stops = $all ? $this->stopRepository->findAllByParams($symbol) : $this->stopRepository->findActive($symbol);
        }

        $this->entityManager->beginTransaction();

        $errors = [];

        if ($stops) {
            $stops = new StopsCollection(...$stops);
            $totalStopsCount = $stops->totalCount();

            if ($slFilterCallbacks) {
                $stops = $stops->filterWithCallback(static function (Stop $stop) use ($slFilterCallbacks, &$errors) {
                    foreach ($slFilterCallbacks as $filterCallback) {
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

            if ($buyOrdersFilterCallbacks) {
                $buyOrders = $buyOrders->filterWithCallback(static function (BuyOrder $buyOrder) use ($buyOrdersFilterCallbacks, &$errors) {
                    foreach ($buyOrdersFilterCallbacks as $filterCallback) {
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
