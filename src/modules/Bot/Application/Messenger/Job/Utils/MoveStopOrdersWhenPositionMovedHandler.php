<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Position\Side;
use App\Bot\Domain\ValueObject\Symbol;
use App\Bot\Infrastructure\ByBit\PositionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MoveStopOrdersWhenPositionMovedHandler
{
    /**
     * @var array float
     */
    private array $lastRunAt = [];

    private const PRICE_STEP = 2;
    private const MOVE_STEP = 1;

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionService $positionService,
    ) {
    }

    public function __invoke(MoveStopOrdersWhenPositionMoved $message)
    {
        $side = $message->positionSide;

        $lastRun = $this->getLastRunAt($side);

        $ticker = $this->exchangeService->getTicker(Symbol::BTCUSDT);
        $position = $this->positionService->getPosition($ticker->symbol, $side);

        if (!$lastRun) {
            $this->lastRunAt[$side->value] = $position->entryPrice;
            return;
        }

        if (
            $side === Side::Sell
                ? ($position->entryPrice < ($lastRun - self::PRICE_STEP))
                : ($position->entryPrice > ($lastRun + self::PRICE_STEP))
        ) {
            $stops = $this->stopRepository->findActive($side);
            foreach ($stops as $stop) {
                $stop->setPrice($side === Side::Sell ? $stop->getPrice() - self::MOVE_STEP : $stop->getPrice() + self::MOVE_STEP);
                $this->stopRepository->save($stop);
            }
            $this->lastRunAt[$side->value] = $position->entryPrice;
        }
    }

    private function getLastRunAt(Side $side)
    {
        return $this->lastRunAt[$side->value] ?? null;
    }
}
