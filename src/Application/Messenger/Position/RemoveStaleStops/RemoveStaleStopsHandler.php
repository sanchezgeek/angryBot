<?php

declare(strict_types=1);

namespace App\Application\Messenger\Position\RemoveStaleStops;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepositoryInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Stop\StopsCollection;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @group liquidation
 */
#[AsMessageHandler]
final readonly class RemoveStaleStopsHandler
{
    public function __construct(
        private PositionServiceInterface $positionService,
        private StopRepositoryInterface $stopRepository,
    ) {
    }

    public function __invoke(RemoveStaleStops $message): void
    {
        if (!($position = $this->getPosition($message->symbol))) {
            return;
        }

        foreach ($this->getStopsAfterLiquidation($position) as $stop) {
            $this->stopRepository->remove($stop);
        }
    }

    private function getStopsAfterLiquidation(Position $position): StopsCollection
    {
        $delayedStops = $this->stopRepository->findActive(symbol: $position->symbol, side: $position->side, qbModifier: function (QueryBuilder $qb) use ($position) {
            $priceField = $qb->getRootAliases()[0] . '.price';
            $qb->andWhere($priceField . ' > :price')->setParameter(':price', $position->liquidationPrice()->value());
        });

        return (new StopsCollection(...$delayedStops))->filterWithCallback(static function (Stop $stop) {
            return
                $stop->isAdditionalStopFromLiquidationHandler() &&
                !$stop->isTakeProfitOrder()
            ;
        });
    }

    private function getPosition(Symbol $symbol): ?Position
    {
        if (!($positions = $this->positionService->getPositions($symbol))) {
            return null;
        }

        $position = $positions[0]->getHedge()?->mainPosition ?? $positions[0];
        if (!$position->liquidationPrice) {
            return null;
        }

        return $position;
    }
}
