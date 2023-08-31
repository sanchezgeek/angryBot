<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Shared\ValueObject\Price;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MoveStopOrdersWhenPositionMovedHandler
{
    /**
     * @var Price[]
     */
    private array $lastRunAt = [];

    private const PRICE_STEP = 3;
    private const MOVE_STEP = 2.8;

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
    ) {
    }

    /**
     * @see \App\Tests\Functional\Bot\Handler\Utils\MoveStopsWhenPositionMovedTest
     */
    public function __invoke(MoveStopOrdersWhenPositionMoved $message): void
    {
        $side = $message->positionSide;

        $lastRun = $this->getLastRunAt($side);

        $ticker = $this->exchangeService->ticker(Symbol::BTCUSDT);
        $position = $this->positionService->getPosition($ticker->symbol, $side);

        if (!$position) {
            return;
        }

        $entryPrice = Price::float($position->entryPrice);

        if (!$lastRun) {
            $this->lastRunAt[$side->value] = $entryPrice;
            return;
        }

        if (
            $side->isShort()
                ? ($entryPrice->less($lastRun->sub(self::PRICE_STEP)))
                : ($entryPrice->greater($lastRun->add(self::PRICE_STEP)))
        ) {
            $delta = abs($entryPrice->value() - $lastRun->value());
            $times = $delta / self::PRICE_STEP;

            $move = $times * self::MOVE_STEP;

            $stops = $this->stopRepository->findActive($side);
            foreach ($stops as $stop) {
                $stopPrice = Price::float($stop->getPrice());

                // @todo '0.025' must be calculated as part of position size
                if ($stop->getVolume() >= 0.025) {
                    $needMove = $side->isShort() ? $stopPrice->greater($entryPrice) : $stopPrice->less($entryPrice);
                } else {
                    $needMove = $side->isShort() ? $stopPrice->greater($entryPrice->sub(100)) : $stopPrice->less($entryPrice->add(100));
                }

                if (!$needMove) {
                    continue;
                }

                $newPrice = $side->isShort() ? $stopPrice->sub($move) : $stopPrice->add($move);
                $stop->setPrice($newPrice->value())->clearOriginalPrice();

                $this->stopRepository->save($stop);
            }

            $this->lastRunAt[$side->value] = $entryPrice;
        }
    }

    private function getLastRunAt(Side $side): ?Price
    {
        return $this->lastRunAt[$side->value] ?? null;
    }
}
