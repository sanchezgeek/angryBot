<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\CacheDecorated;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Cache\PositionsCache;
use DateInterval;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function sprintf;

final readonly class ByBitLinearPositionCacheDecoratedService implements PositionServiceInterface, PositionsCache
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    /** @todo | inject into service? */
    public const POSITION_TTL = '4 seconds';

    /**
     * @param ByBitLinearPositionService $positionService
     */
    public function __construct(
        private PositionServiceInterface $positionService,
        private CacheInterface $cache,
    ) {
    }

    public function getOpenedPositionsSymbols(array $except = []): array
    {
        return $this->positionService->getOpenedPositionsSymbols($except);
    }

    public function getOpenedPositionsRawSymbols(): array
    {
        return $this->positionService->getOpenedPositionsRawSymbols();
    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService\GetPositionTest
     */
    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        $positions = $this->getPositions($symbol);

        foreach ($positions as $position) {
            if ($position->side === $side) {
                return $position;
            }
        }

        return null;
    }

    public function getPositions(Symbol $symbol): array
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
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearPositionService\ByBitLinearPositionCacheDecoratedService\AddStopTest
     */
    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string
    {
        return $this->positionService->addConditionalStop($position, $price, $qty, $triggerBy);
    }

    public function clearPositionsCache(Symbol $symbol, ?Side $positionSide = null): void
    {
        // @todo | cache | all symbols?
        $this->cache->delete(
            self::positionsCacheKey($symbol)
        );
    }

    public static function positionsCacheKey(Symbol $symbol): string
    {
        return sprintf('api_%s_%s_positions_data', self::ASSET_CATEGORY->value, $symbol->value);
    }
}
