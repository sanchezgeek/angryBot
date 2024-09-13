<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\PositionLiquidatedBeforeOrderPriceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;

interface TradingSandboxInterface
{
    /**
     * @return SandboxState New state after orders exec
     *
     * @throws SandboxInsufficientAvailableBalanceException
     * @throws PositionLiquidatedBeforeOrderPriceException
     */
    public function processOrders(SandboxBuyOrder|BuyOrder|SandboxStopOrder|Stop ...$orders): SandboxState;

    public function getCurrentState(): SandboxState;

    public function setState(SandboxState $state): self;
}
