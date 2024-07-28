<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox\TradingSandbox;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Trading\Sandbox\TradingSandbox;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Infrastructure\ByBit\Service\ByBitCommissionProvider;
use PHPUnit\Framework\TestCase;

class AbstractTestOfTradingSandbox extends TestCase
{
    protected const SYMBOL = Symbol::BTCUSDT;

    protected TradingSandbox $tradingSandbox;

    protected function setUp(): void
    {
        $this->tradingSandbox = new TradingSandbox(
            new OrderCostCalculator(new ByBitCommissionProvider()),
            new CalcPositionLiquidationPriceHandler(),
            self::SYMBOL
        );
    }
}