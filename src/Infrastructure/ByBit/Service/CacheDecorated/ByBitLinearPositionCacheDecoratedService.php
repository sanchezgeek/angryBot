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

use function array_filter;
use function sprintf;

final readonly class ByBitLinearPositionCacheDecoratedService implements PositionServiceInterface, PositionsCache
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    /** @todo | inject into service? */
    private const POSITION_TTL = '12 seconds';
    private const OPENED_POSITIONS_SYMBOLS_TTL = '1 minute';

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
        $key = sprintf('api_%s_opened_positions_symbols', self::ASSET_CATEGORY->value);;

        $all = $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(DateInterval::createFromDateString(self::OPENED_POSITIONS_SYMBOLS_TTL));

            return $this->positionService->getOpenedPositionsSymbols([]);
        });

        return array_values(
            array_filter($all, static fn(Symbol $symbol): bool => !in_array($symbol, $except, true))
        );
    }

    public function getOpenedPositionsRawSymbols(): array
    {
        return $this->positionService->getOpenedPositionsRawSymbols();
    }

    public function setLeverage(Symbol $symbol, float $forBuy, float $forSell): void
    {
        $this->positionService->setLeverage($symbol, $forBuy, $forSell);
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
        $this->cache->delete(
            self::positionsCacheKey($symbol)
        );
    }

    private static function positionsCacheKey(Symbol $symbol): string
    {
        return sprintf('api_%s_%s_positions_data', self::ASSET_CATEGORY->value, $symbol->value);
    }
}
