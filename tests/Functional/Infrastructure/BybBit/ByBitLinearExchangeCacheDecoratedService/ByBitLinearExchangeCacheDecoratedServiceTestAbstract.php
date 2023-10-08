<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\ByBitLinearExchangeCacheDecoratedService;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use App\Infrastructure\ByBit\ByBitLinearExchangeCacheDecoratedService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

abstract class ByBitLinearExchangeCacheDecoratedServiceTestAbstract extends KernelTestCase
{
    private const ASSET_CATEGORY = AssetCategory::linear;
    protected const WORKER_DEBUG_HASH = '123456';

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
            $this->cache
        );
    }

    protected function getTickerCacheKey(Symbol $symbol): string
    {
        return \sprintf('%s_%s_ticker', self::ASSET_CATEGORY->value, $symbol->value);
    }
}
