<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\BuyOrder\ResetBuyOrdersActiveState;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Clock\ClockInterface;
use App\Domain\Stop\Helper\PnlHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Bot\Handler\ButOrder\ResetBuyOrdersActiveState\ResetBuyOrdersActiveStateHandlerTest
 */
#[AsMessageHandler]
final readonly class ResetBuyOrdersActiveStateHandler
{
    public const int ACTIVE_STATE_TTL = 846000;

    /**
     * @todo | priceChange statistics | ResetBuyOrdersActiveStateHandler | use statistics for get appropriate allowed distance
     */
    private const array ALLOWED_PNL_DELTA = [
        SymbolEnum::BTCUSDT->value => 50,
        SymbolEnum::ETHUSDT->value => 100,
        SymbolEnum::BNBUSDT->value => 200,
        'other' => 500,
    ];

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
         * pushed to exchange stop should have an impact?
         */

        $now = $this->clock->now();

        /** @var array<array{symbol: SymbolInterface, items: BuyOrder[]}> $map */
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
                $allowedPnlDelta = self::ALLOWED_PNL_DELTA[$symbol->name()] ?? self::ALLOWED_PNL_DELTA['other'];
                if ($orderPriceDistancePercentPnl->value() <= $allowedPnlDelta) {
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
