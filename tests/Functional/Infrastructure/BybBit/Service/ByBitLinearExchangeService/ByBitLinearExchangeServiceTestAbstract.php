<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService;

use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Result\ApiErrorInterface;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

use function sprintf;

abstract class ByBitLinearExchangeServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;

    protected const ASSET_CATEGORY = AssetCategory::linear;
    protected const WORKER_DEBUG_HASH = '123456';

    protected ByBitLinearExchangeService $service;

    protected function setUp(): void
    {
        $this->service = new ByBitLinearExchangeService(
            $this->initializeApiClient()
        );
    }
}
