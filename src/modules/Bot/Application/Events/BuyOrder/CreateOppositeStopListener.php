<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\BuyOrder;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class CreateOppositeStopListener
{
    public function __construct(
        private readonly ExchangeServiceInterface $exchangeService,
        private readonly PositionServiceInterface $positionService,
    ) {
    }

    public function __invoke(BuyOrderPushedToExchange $event): void
    {
        // For now only for test purposes
        if (!$event->dryRun) {
            return;
        }

        $symbol = Symbol::BTCUSDT;
        $order = $event->order;

        $position = $this->positionService->getPosition($symbol, $order->getPositionSide());
        $ticker = $this->exchangeService->ticker($symbol);

//        $stopData = $this->createStop($position, $ticker, $order);
        $stopData = [
            'triggerPrice' => 123456,
            'strategy' => 'just test',
        ];

        $event->setStopData($stopData);
    }
}
