<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\CacheDecorated;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\PermissionDeniedException;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Cache\PositionsCache;
use App\Trading\Domain\Symbol\SymbolInterface;
use DateInterval;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function sprintf;

final readonly class ByBitLinearPositionCacheDecoratedService implements PositionServiceInterface, PositionsCache
{
    private const AssetCategory ASSET_CATEGORY = AssetCategory::linear;

    /** @todo | inject into service? */
    public const string POSITION_TTL = '4 seconds';

    /**
     * @param ByBitLinearPositionService $positionService
     */
    public function __construct(
        private PositionServiceInterface $positionService,
        private CacheInterface $cache,
    ) {
    }

    public function getOpenedPositionsSymbols(SymbolInterface ...$except): array
    {
        return $this->positionService->getOpenedPositionsSymbols(...$except);
    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService\GetPositionTest
     */
    public function getPosition(SymbolInterface $symbol, Side $side): ?Position
    {
        $positions = $this->getPositions($symbol);

        return array_find($positions, static fn($position) => $position->side === $side);
    }

    public function getPositions(SymbolInterface $symbol): array
    {
        $key = self::positionsCacheKey($symbol);

        return $this->cache->get($key, function (ItemInterface $item) use ($symbol) {
            $item->expiresAfter(DateInterval::createFromDateString(self::POSITION_TTL));

            return $this->positionService->getPositions($symbol);
        });
    }

    /**
     * @inheritDoc
     *
     * @throws MaxActiveCondOrdersQntReached
     * @throws TickerOverConditionalOrderTriggerPrice
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     * @throws PermissionDeniedException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService\AddStopTest
     */
    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string
    {
        return $this->positionService->addConditionalStop($position, $price, $qty, $triggerBy);
    }

    public function clearPositionsCache(SymbolInterface $symbol, ?Side $positionSide = null): void
    {
        $this->cache->delete(
            self::positionsCacheKey($symbol)
        );
    }

    public static function positionsCacheKey(SymbolInterface $symbol): string
    {
        return sprintf('api_%s_%s_positions_data', self::ASSET_CATEGORY->value, $symbol->name());
    }
}
