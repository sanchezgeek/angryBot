<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService;

use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Tests\Mixin\Tester\ByBitV5ApiTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class ByBitLinearPositionServiceTestAbstract extends KernelTestCase
{
    use ByBitV5ApiTester;

    protected ByBitLinearPositionService $service;

    /**
     * @var AbstractByBitApiRequest[]
     */
    private array $expectedApiRequestsAfterTest = [];

    protected function setUp(): void
    {
        $this->service = new ByBitLinearPositionService(
            $this->initializeApiClient(),
        );
    }
}
