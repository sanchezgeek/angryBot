<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeService;

use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\ByBitLinearExchangeService;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class ByBitLinearExchangeServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;

    protected const ASSET_CATEGORY = AssetCategory::linear;
    protected const WORKER_DEBUG_HASH = '123456';

    protected ByBitLinearExchangeService $service;

    protected function setUp(): void
    {
        $this->service = new ByBitLinearExchangeService(
            $this->initializeApiClient(),
            self::WORKER_DEBUG_HASH
        );
    }
}
