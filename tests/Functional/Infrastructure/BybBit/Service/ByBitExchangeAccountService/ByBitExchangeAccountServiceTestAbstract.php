<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitExchangeAccountService;

use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\Service\Account\ByBitExchangeAccountService;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

use function sprintf;

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
            $this->appErrorLogger
        );
    }
}
