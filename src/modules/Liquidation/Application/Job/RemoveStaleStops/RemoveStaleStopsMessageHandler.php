<?php

declare(strict_types=1);

namespace App\Liquidation\Application\Job\RemoveStaleStops;

use App\Bot\Domain\Repository\StopRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * @see \App\Tests\Functional\Modules\Liquidation\Job\RemoveOrdersWithFakeExchangeOrderIdTest
 */
#[AsMessageHandler]
final readonly class RemoveStaleStopsMessageHandler
{
    public function __construct(
        private StopRepositoryInterface $stopRepository
    ) {
    }

    public function __invoke(RemoveStaleStopsMessage $message): void
    {
        $stops = $this->stopRepository->findStopsWithFakeExchangeOrderId();

        foreach ($stops as $stop) {
            $this->stopRepository->remove($stop);
        }
    }
}
