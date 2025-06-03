<?php

declare(strict_types=1);

namespace App\Tests\Mixin\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxStopOrder;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use PHPUnit\Framework\MockObject\MockObject;

trait SandboxUnitTester
{
    private TradingSandboxFactoryInterface|MockObject $tradingSandboxFactory;
    private SandboxStateFactoryInterface|MockObject $sandboxStateFactory;

    protected function initializeSandboxTester(?TradingSandboxFactoryInterface $tradingSandboxFactory = null, ?SandboxStateFactoryInterface $sandboxStateFactory = null): void
    {
        $this->tradingSandboxFactory = $tradingSandboxFactory ?? $this->createMock(TradingSandboxFactoryInterface::class);
        $this->sandboxStateFactory = $sandboxStateFactory ?? $this->createMock(SandboxStateFactoryInterface::class);
    }

    protected function makeSandboxThatWillThrowException(SandboxBuyOrder|SandboxStopOrder $order, \Throwable $exception): TradingSandboxInterface&MockObject
    {
        $sandboxMock = $this->emptySandbox();
        $sandboxMock->expects(self::once())->method('processOrders')
            ->with($order)
            ->willThrowException($exception);

        return $sandboxMock;
    }

    protected function emptySandbox(): TradingSandboxInterface&MockObject
    {
        return $this->createMock(TradingSandboxInterface::class);
    }

    private static function sampleSandboxState(Ticker $ticker, Position ...$positions): SandboxState
    {
        return new SandboxState($ticker, new ContractBalance($ticker->symbol->associatedCoin(), 100500, 100500, 100500), $ticker->symbol->associatedCoinAmount(100500), ...$positions);
    }

    protected function mockFactoryToReturnSandbox(SymbolInterface $requestedSymbol, TradingSandboxInterface $sandboxMock): void
    {
        $this->tradingSandboxFactory->expects(self::once())->method('empty')->with($requestedSymbol)->willReturn($sandboxMock);
    }
}
