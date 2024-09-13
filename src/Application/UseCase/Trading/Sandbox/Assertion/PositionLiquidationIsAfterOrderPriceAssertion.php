<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Assertion;

use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\PositionLiquidatedBeforeOrderPriceException;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;

final readonly class PositionLiquidationIsAfterOrderPriceAssertion
{
    private function __construct(private Position $position, private SandboxBuyOrder|SandboxStopOrder $order)
    {
    }

    public static function create(Position $position, SandboxBuyOrder|SandboxStopOrder|BuyOrder|Stop $order): self
    {
        $order = match (true) {
            $order instanceof SandboxBuyOrder,
            $order instanceof SandboxStopOrder => $order,
            $order instanceof BuyOrder => SandboxBuyOrder::fromBuyOrder($order),
            $order instanceof Stop => SandboxStopOrder::fromStop($order),
        };

        return new self($position, $order);
    }

    /**
     * @throws PositionLiquidatedBeforeOrderPriceException
     */
    public function check(): void
    {
        if ($this->position->isLong() && $this->order->price <= $this->position->liquidationPrice) {
            throw new PositionLiquidatedBeforeOrderPriceException($this->order, $this->position, 'Order price is less than position.liquidationPrice');
        } elseif ($this->position && $this->position->isShort() && $this->order->price >= $this->position->liquidationPrice) {
            throw new PositionLiquidatedBeforeOrderPriceException($this->order, $this->position, 'Order price is greater than position.liquidationPrice');
        }
    }
}
