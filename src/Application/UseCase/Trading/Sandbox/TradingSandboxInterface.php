<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\Out\ExecutionStepResult;
use App\Application\UseCase\Trading\Sandbox\Enum\SandboxErrorsHandlingType;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionLiquidatedBeforeOrderPriceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxInsufficientAvailableBalanceException;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxPositionNotFoundException;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;

interface TradingSandboxInterface
{
    /**
     * @throws SandboxInsufficientAvailableBalanceException
     * @throws SandboxPositionLiquidatedBeforeOrderPriceException
     * @throws SandboxPositionNotFoundException
     */
    public function processOrders(SandboxBuyOrder|BuyOrder|SandboxStopOrder|Stop ...$orders): ExecutionStepResult;

    public function getCurrentState(): SandboxStateInterface;

    public function setState(SandboxStateInterface $state): void;

    public function setErrorsHandlingType(SandboxErrorsHandlingType $errorsHandlingType): void;

    public function addIgnoredException(string $exceptionClass): void;
}
