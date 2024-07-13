<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitExchangeAccountService;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class ByBitExchangeAccountServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;

    protected const ASSET_CATEGORY = AssetCategory::linear;
    protected const WORKER_DEBUG_HASH = '123456';

    protected ByBitExchangeAccountService $service;
    protected LoggerInterface $appErrorLogger;

    protected function setUp(): void
    {
        $this->appErrorLogger = $this->createMock(LoggerInterface::class);

        $this->service = new ByBitExchangeAccountService(
            $this->initializeApiClient(),
            $this->appErrorLogger,
            self::getContainer()->get(OrderCostCalculator::class),
            self::getContainer()->get(CalcPositionLiquidationPriceHandler::class),
            self::getContainer()->get(PositionServiceInterface::class),
        );
    }
}
