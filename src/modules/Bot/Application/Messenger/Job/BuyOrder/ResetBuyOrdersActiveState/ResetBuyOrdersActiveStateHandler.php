<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\BuyOrder\ResetBuyOrdersActiveState;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Clock\ClockInterface;
use App\Domain\Stop\Helper\PnlHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Bot\Handler\ButOrder\ResetBuyOrdersActiveState\ResetBuyOrdersActiveStateHandlerTest
 */
#[AsMessageHandler]
final readonly class ResetBuyOrdersActiveStateHandler
{
    public const int ACTIVE_STATE_TTL = 20000;

    /**
     * @todo | priceChange statistics | ResetBuyOrdersActiveStateHandler | use statistics for get appropriate allowed distance
     */
    private const float ALLOWED_PNL_DELTA = 50;

    public function __construct(
        private BuyOrderRepository $buyOrderRepository,
        private EntityManagerInterface $entityManager,
        private ExchangeServiceInterface $exchangeService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ResetBuyOrdersActiveState $message): void
    {
        /**
         * Should pushed to exchange stop have an impact?
         */

        $now = $this->clock->now();

        /** @var array<array{symbol: string, items: BuyOrder[]}> $map */
        $map = [];
        foreach ($this->buyOrderRepository->getAllActiveOrders() as $buyOrder) {
            $symbol = $buyOrder->getSymbol();
            $symbolName = $symbol->name();

            $map[$symbolName] = $map[$symbolName] ?? ['symbol' => $symbol, 'items' => []];
            $map[$symbolName]['items'][] = $buyOrder;
        }

        foreach ($map as $item) {
            $symbol = $item['symbol'];
            $orders = $item['items'];
            $ticker = $this->exchangeService->ticker($symbol);

            foreach ($orders as $buyOrder) {
                if (!$activeStateSetAt = $buyOrder->getActiveStateChangeTimestamp()) {
                    continue;
                }
                $orderPrice = $buyOrder->getPrice();

                $orderPriceDistancePercentPnl = PnlHelper::convertAbsDeltaToPnlPercentOnPrice($ticker->indexPrice->deltaWith($orderPrice), $ticker->indexPrice);
                if ($orderPriceDistancePercentPnl->value() <= self::ALLOWED_PNL_DELTA) {
                    continue;
                }

                if ($now->getTimestamp() - $activeStateSetAt > self::ACTIVE_STATE_TTL) {
                    $buyOrder->setIdle();
                }
            }
        }

        $this->entityManager->flush();
    }
}
