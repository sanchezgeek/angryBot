<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\MoveStops;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Domain\Price\Enum\PriceMovementDirection;
use App\Domain\Stop\Helper\PnlHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @see \App\Tests\Functional\Modules\Stop\Applicaiton\UseCase\MoveStops\MoveStopsToBreakevenHandlerTest
 */
final readonly class MoveStopsToBreakevenHandler implements MoveStopsToBreakevenHandlerInterface
{
    public function __construct(
        private StopRepository $stopRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function handle(MoveStopsToBreakevenEntryDto $entryDto): void
    {
        $position = $entryDto->position;
        $symbol = $position->symbol;
        $side = $position->side;

        if (!$stops = $this->getStopsToHandle($entryDto)) {
            return;
        }

        usort(
            $stops,
            $side->isShort()
                ? static fn (Stop $a, Stop $b) => $a->getPrice() <=> $b->getPrice()
                : static fn (Stop $a, Stop $b) => $b->getPrice() <=> $a->getPrice()
        );

        $firstStop = $stops[array_key_first($stops)];
        $firstStopPrice = $symbol->makePrice($firstStop->getPrice());

        $targetPriceDiffWithPositionEntry = PnlHelper::convertPnlPercentOnPriceToAbsDelta($entryDto->positionPnlPercent, $position->entryPrice());
        $targetPrice = $position->entryPrice()->modifyByDirection($side, PriceMovementDirection::TO_PROFIT, $targetPriceDiffWithPositionEntry);

        $priceDifference = $targetPrice->differenceWith($firstStopPrice);
        $isStopsPlacedAfterTarget = $priceDifference->isProfitFor($side);

        if (!$isStopsPlacedAfterTarget) {
            return;
        }

        $priceDelta = $priceDifference->absDelta();

        $this->entityManager->wrapInTransaction(static function() use ($stops, $symbol, $priceDelta, $side) {
            foreach ($stops as $stop) {
                $initialPrice = $symbol->makePrice($stop->getPrice());

                $stop->setPrice(
                    $initialPrice->modifyByDirection($side, PriceMovementDirection::TO_PROFIT, $priceDelta)->value()
                );
            }
        });
    }

    /**
     * @return Stop[]
     */
    private function getStopsToHandle(MoveStopsToBreakevenEntryDto $entryDto): array
    {
        $position = $entryDto->position;
        $activeStops = $this->stopRepository->findActive($position->symbol, $position->side);

        return $entryDto->excludeFixations
            ? array_filter($activeStops, static fn (Stop $stop) => !($stop->isStopAfterOtherSymbolLoss() || $stop->isStopAfterFixHedgeOppositePosition()))
            : $activeStops
        ;
    }
}
