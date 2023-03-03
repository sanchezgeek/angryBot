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
     * @var float[]
     */
    private array $lastRunAt = [];

    private const PRICE_STEP = 3;
    private const MOVE_STEP = 2.1;

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

        $ticker = $this->exchangeService->ticker(Symbol::BTCUSDT);
        $position = $this->positionService->getPosition($ticker->symbol, $side);

        if (!$lastRun) {
            $this->lastRunAt[$side->value] = $position->entryPrice;
            return;
        }

        if (
            $side === Side::Sell
                ? ($position->entryPrice <= ($lastRun - self::PRICE_STEP))
                : ($position->entryPrice >= ($lastRun + self::PRICE_STEP))
        ) {
            $delta = abs($position->entryPrice - $lastRun);
            $times = $delta / self::PRICE_STEP;
            $move = $times * self::MOVE_STEP;

            $stops = $this->stopRepository->findActive($side);
            foreach ($stops as $stop) {
                $needMove = true;
                if ($stop->getVolume() > 0.05) {
                    $needMove =
                        $position->side === Side::Sell
                            ? $stop->getPrice() > $position->entryPrice
                            : $stop->getPrice() < $position->entryPrice
                    ;
                }

                if ($needMove) {
                    $stop->setPrice($side === Side::Sell ? $stop->getPrice() - $move : $stop->getPrice() + $move);
                    $stop->clearOriginalPrice();
                    $this->stopRepository->save($stop);
                }
            }

            $this->lastRunAt[$side->value] = $position->entryPrice;
        }
    }

    private function getLastRunAt(Side $side)
    {
        return $this->lastRunAt[$side->value] ?? null;
    }
}
