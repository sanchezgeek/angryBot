<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Tests\Mixin\Logger\AppErrorsSymfonyLoggerTrait;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class ByBitLinearPositionServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;
    use AppErrorsSymfonyLoggerTrait;

    protected ByBitLinearPositionService $service;

    protected function setUp(): void
    {
        $this->service = new ByBitLinearPositionService(
            $this->initializeApiClient(),
            self::getTestAppErrorsLogger(),
        );
    }
}
