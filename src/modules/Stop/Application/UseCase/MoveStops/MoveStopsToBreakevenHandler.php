<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\MoveStops;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Domain\Price\Enum\PriceMovementDirection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MoveStopsToBreakevenHandler implements MoveStopsToBreakevenHandlerInterface
{
    public function __construct(
        private StopRepository $stopRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function handle(MoveStopsEntryDto $entryDto): void
    {
        $position = $entryDto->position;
        $symbol = $position->symbol;
        $side = $position->side;

        $activeStops = $this->stopRepository->findActive($symbol, $side);

        if (!$activeStops) {
            return;
        }

        usort($activeStops, static fn (Stop $a, Stop $b) => $a->getPrice() <=> $b->getPrice());

        $firstStop = $activeStops[array_key_first($activeStops)];

        $firstStopPrice = $symbol->makePrice($firstStop->getPrice());
        $priceDelta = $position->entryPrice()->differenceWith($firstStopPrice);

        $isStopsPlacedAfterPosition = $priceDelta->isProfitFor($side);

        if (!$isStopsPlacedAfterPosition) {
            return;
        }

        $delta = $priceDelta->absDelta();

        $this->entityManager->wrapInTransaction(static function() use ($activeStops, $symbol, $delta, $side) {
            foreach ($activeStops as $stop) {
                $initialPrice = $symbol->makePrice($stop->getPrice());

                $stop->setPrice(
                    $initialPrice->modifyByDirection($side, PriceMovementDirection::TO_PROFIT, $delta)->value()
                );
            }
        });
    }
}
