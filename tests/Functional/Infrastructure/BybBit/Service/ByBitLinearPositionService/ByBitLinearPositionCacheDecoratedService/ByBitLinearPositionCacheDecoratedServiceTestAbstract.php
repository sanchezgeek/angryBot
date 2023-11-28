<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

abstract class ByBitLinearPositionCacheDecoratedServiceTestAbstract extends KernelTestCase
{
    private const ASSET_CATEGORY = AssetCategory::linear;

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
        return sprintf('api_%s_%s_%s_position_data', self::ASSET_CATEGORY->value, $symbol->value, $side->value);
    }
}
