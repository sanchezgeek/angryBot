<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function sprintf;

abstract class ByBitLinearPositionCacheDecoratedServiceTestAbstract extends KernelTestCase
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    protected ArrayAdapter $cache;
    protected PositionServiceInterface $innerService;

    protected ByBitLinearPositionCacheDecoratedService $service;

    protected function setUp(): void
    {
        $this->innerService = $this->createMock(PositionServiceInterface::class);
        $this->cache = new ArrayAdapter();

        $this->service = new ByBitLinearPositionCacheDecoratedService(
            $this->innerService,
            $this->cache
        );
    }

    protected static function getPositionsCacheKey(SymbolInterface $symbol): string
    {
        return sprintf('api_%s_%s_positions_data', self::ASSET_CATEGORY->value, $symbol->value);
    }
}
