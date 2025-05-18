<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\MarketBuy;

use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Application\UseCase\Trading\Sandbox\Factory\SandboxStateFactoryInterface;
use App\Application\UseCase\Trading\Sandbox\Factory\TradingSandboxFactoryInterface;
use App\Tests\Mixin\RateLimiterAwareTest;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\PHPUnit\TestLogger;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group market-buy
 */
class MarketBuyHandlerTest extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;
    use SettingsAwareTest;
    use RateLimiterAwareTest;

    private const SAFE_PRICE_DISTANCE = 2000;

    private SandboxStateFactoryInterface|MockObject $sandboxStateFactory;
    private TradingSandboxFactoryInterface|MockObject $executionSandboxFactory;
    private TradingParametersProviderInterface|MockObject $mainPositionLiquidationParametersMock;
    private LoggerInterface $logger;

    private MarketBuyHandler $marketBuyHandler;

    protected function setUp(): void
    {
        $this->sandboxStateFactory = $this->createMock(SandboxStateFactoryInterface::class);
        $this->executionSandboxFactory = $this->createMock(TradingSandboxFactoryInterface::class);
        $this->logger = new TestLogger();

        $this->mainPositionLiquidationParametersMock = $this->createMock(TradingParametersProviderInterface::class);
        $this->mainPositionLiquidationParametersMock->method('safeLiquidationPriceDelta')->willReturn((float)self::SAFE_PRICE_DISTANCE);

        $this->marketBuyHandler = self::getContainer()->get(MarketBuyHandler::class);
    }

    public function testTest(): void
    {
        self::markTestSkipped();
    }
}
