<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\PushStopsToTexchange\Strategy;

use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Domain\Order\ExchangeOrder;
use App\Stop\Application\UseCase\PushStopsToTexchange\Dto\PushStopResult;
use App\Stop\Application\UseCase\PushStopsToTexchange\Dto\PusStopEntry;
use App\Stop\Application\UseCase\PushStopsToTexchange\PushStopStrategyInterface;

final class ConditionalStopPushStrategy implements PushStopStrategyInterface
{
    public function __construct(
        private readonly OrderServiceInterface $orderService
    ) {
    }

    public function supports(PusStopEntry $entryDto): bool
    {
        // TODO: Implement supports() method.
    }

    public function push(PusStopEntry $entryDto): PushStopResult
    {
        $exchangeOrderId = $this->orderService->closeByMarket($position, $stop->getVolume());
        $stopsClosedByMarket[] = new ExchangeOrder($position->symbol, $stop->getVolume(), $stop->getPrice());
    }
}
