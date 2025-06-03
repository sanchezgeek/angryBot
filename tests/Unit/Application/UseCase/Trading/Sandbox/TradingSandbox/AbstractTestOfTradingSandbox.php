<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox\TradingSandbox;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Application\UseCase\Trading\Sandbox\TradingSandbox;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\Service\ByBitCommissionProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * @todo | sandbox | Add tests Sandbox for SandboxErrorsHandlingType::CollectAndContinue
 * @see \App\Application\UseCase\Trading\Sandbox\Enum\SandboxErrorsHandlingType::CollectAndContinue
 */
class AbstractTestOfTradingSandbox extends TestCase
{
    protected const SYMBOL = SymbolEnum::BTCUSDT;

    protected TradingSandbox $tradingSandbox;

    protected function setUp(): void
    {
        $this->tradingSandbox = new TradingSandbox(
            new OrderCostCalculator(new ByBitCommissionProvider()),
            new CalcPositionLiquidationPriceHandler(),
            self::SYMBOL,
        );
    }

    protected static function assertSandboxPositionsIsEqualsTo(array $expectedPositions, SandboxState $sandboxState): void
    {
        $expectedPositionsSides = array_map(static fn(Position $p) => $p->side, $expectedPositions);
        $actualPositions = array_map(fn(Side $side) => $sandboxState->getPosition($side), $expectedPositionsSides);

        foreach ($expectedPositions as $position)   $position->initializeHedge();
        foreach ($actualPositions as $position)     $position->initializeHedge();

        self::assertEquals($expectedPositions, $actualPositions);
    }

    protected static function assertSandboxStateEqualsToExpected(SandboxState $expectedState, SandboxState $state): void
    {
        foreach ($state->getPositions() as $position) {
            $position->initializeHedge();
        }

        foreach ($expectedState->getPositions() as $position) {
            $position->initializeHedge();
        }

        self::assertEquals($expectedState, $state);
    }
}
