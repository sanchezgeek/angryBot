<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\Trade\ByBitOrderServiceTest;

use App\Infrastructure\ByBit\Service\Trade\ByBitOrderService;
use App\Infrastructure\Cache\PositionsCache;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class ByBitOrderServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;

    protected ByBitOrderService $service;

    protected function setUp(): void
    {
        $this->service = new ByBitOrderService(
            $this->initializeApiClient(),
            self::getContainer()->get(PositionsCache::class),
        );
    }
}
