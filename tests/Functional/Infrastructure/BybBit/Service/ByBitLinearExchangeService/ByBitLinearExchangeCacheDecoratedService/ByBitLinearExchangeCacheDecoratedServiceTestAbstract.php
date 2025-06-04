<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\ByBitLinearExchangeCacheDecoratedService;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearExchangeCacheDecoratedService;
use App\Trading\Domain\Symbol\SymbolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

abstract class ByBitLinearExchangeCacheDecoratedServiceTestAbstract extends KernelTestCase
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    protected ArrayAdapter $cache;
    protected ExchangeServiceInterface $innerService;
    protected EventDispatcherInterface $eventDispatcherMock;

    protected ByBitLinearExchangeCacheDecoratedService $service;

    protected function setUp(): void
    {
        $this->innerService = $this->createMock(ExchangeServiceInterface::class);
        $this->eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $this->cache = new ArrayAdapter();

        $this->service = new ByBitLinearExchangeCacheDecoratedService(
            $this->innerService,
            $this->eventDispatcherMock,
            $this->cache,
            $this->cache,
        );
    }

    protected function getTickerCacheKey(SymbolInterface $symbol): string
    {
        return sprintf('api_%s_%s_ticker', self::ASSET_CATEGORY->value, $symbol->name());
    }
}
