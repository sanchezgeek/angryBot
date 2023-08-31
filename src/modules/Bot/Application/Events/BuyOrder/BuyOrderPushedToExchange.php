<?php

declare(strict_types=1);

namespace App\Bot\Application\Events\BuyOrder;

use App\Bot\Application\Events\LoggableEvent;
use App\Bot\Domain\Entity\BuyOrder;
use App\Domain\Position\ValueObject\Side;

final class BuyOrderPushedToExchange extends LoggableEvent
{
    private ?array $stopData = null;

    public function __construct(public BuyOrder $order, public bool $dryRun = false)
    {
    }

    public function getLog(): string
    {
        $tpl = '%sBuy%s %.3f | $%.2f';

        $params = [
            $sign = ($this->order->getPositionSide() === Side::Sell ? '---' : '+++'),
            $sign,
            $this->order->getVolume(),
            $this->order->getPrice(),
        ];

        if ($this->stopData) {
            $tpl .= ' (stop: $%.2f with %s strategy)';

            $params[] = $this->stopData['triggerPrice'];
            $params[] = $this->stopData['strategy'];
        }

        return \sprintf($tpl, ...$params);
    }

    public function getContext(): array
    {
        $context = ['exchange.orderId' => $this->order->getExchangeOrderId()];

        if ($this->stopData) {
            $context['`stop`'] = $this->stopData;
        }

        return $context;
    }

    public function setStopData(array $stopData): self
    {
        $this->stopData = $stopData;

        return $this;
    }
}
