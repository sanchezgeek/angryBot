<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\ByBitLinearPositionCacheDecoratedService;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function sleep;

abstract class ByBitLinearPositionCacheDecoratedServiceTestAbstract extends KernelTestCase
{
    protected ArrayAdapter $cache;
    protected PositionServiceInterface $innerService;
    protected EventDispatcherInterface $eventDispatcherMock;

    protected ByBitLinearPositionCacheDecoratedService $service;

    protected function setUp(): void
    {
        $this->innerService = $this->createMock(PositionServiceInterface::class);
        $this->eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $this->cache = new ArrayAdapter();

        $this->service = new ByBitLinearPositionCacheDecoratedService(
            $this->innerService,
            $this->eventDispatcherMock,
            $this->cache
        );
    }

    protected function getPositionCacheItemKey(Symbol $symbol, Side $side): string
    {
        return \sprintf('apiV5_position_data_%s_%s', $symbol->value, $side->value);
    }
}
