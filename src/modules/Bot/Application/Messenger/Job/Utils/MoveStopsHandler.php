<?php

declare(strict_types=1);

namespace App\Bot\Application\Messenger\Job\Utils;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MoveStopsHandler
{
    /**
     * @var Price[]
     */
    private array $lastRunAt = [];

    public const PRICE_STEP = 3;
    public const MOVE_STEP = 3.1;

    public function __construct(
        private readonly StopRepository $stopRepository,
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
    ) {
    }

    /**
     * @see \App\Tests\Functional\Bot\Handler\Utils\MoveStopsWhenPositionMovedTest
     */
    public function __invoke(MoveStops $message): void
    {
        $side = $message->positionSide;
        $symbol = $message->symbol;

        $lastRun = $this->getLastRunAt($side);

        $ticker = $this->exchangeService->ticker($symbol);
        $position = $this->positionService->getPosition($symbol, $side);

        if (!$position) {
            return;
        }

        $entryPrice = $position->entryPrice();

        if (!$lastRun) {
            $this->lastRunAt[$side->value] = $entryPrice;
            return;
        }

        if (
            $side->isShort()
                ? ($entryPrice->lessThan($lastRun->sub(self::PRICE_STEP)))
                : ($entryPrice->greaterThan($lastRun->add(self::PRICE_STEP)))
        ) {
            $delta = abs($entryPrice->value() - $lastRun->value());
            $times = $delta / self::PRICE_STEP;

            $move = $times * self::MOVE_STEP;

            $stops = $this->stopRepository->findActive($symbol, $side);
            foreach ($stops as $stop) {
                $stopPrice = $symbol->makePrice($stop->getPrice());

                // @todo '0.025' must be calculated as part of position size
                if ($stop->getVolume() >= 0.025) {
                    $needMove = $side->isShort() ? $stopPrice->greaterThan($entryPrice) : $stopPrice->lessThan($entryPrice);
                } else {
                    $needMove = $side->isShort() ? $stopPrice->greaterThan($entryPrice->sub(100)) : $stopPrice->lessThan($entryPrice->add(100));
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
