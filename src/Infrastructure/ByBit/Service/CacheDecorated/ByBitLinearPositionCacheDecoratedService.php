<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\CacheDecorated;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\ByBitLinearPositionService;
use App\Infrastructure\ByBit\Service\Exception\Trade\CannotAffordOrderCost;
use App\Infrastructure\ByBit\Service\Exception\Trade\MaxActiveCondOrdersQntReached;
use App\Infrastructure\ByBit\Service\Exception\Trade\TickerOverConditionalOrderTriggerPrice;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use DateInterval;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function sprintf;

final readonly class ByBitLinearPositionCacheDecoratedService implements PositionServiceInterface
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    /** @todo | inject into service? */
    private const POSITION_TTL = '6 seconds';

    /**
     * @param ByBitLinearPositionService $positionService
     */
    public function __construct(
        private PositionServiceInterface $positionService,
        private EventDispatcherInterface $events,
        private CacheInterface $cache,
    ) {

    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService\GetPositionTest
     */
    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        $key = sprintf('api_%s_%s_%s_position_data', self::ASSET_CATEGORY->value, $symbol->value, $side->value);

        return $this->cache->get($key, function (ItemInterface $item) use ($symbol, $side) {
            $item->expiresAfter(DateInterval::createFromDateString(self::POSITION_TTL));

            if ($position = $this->positionService->getPosition($symbol, $side)) {
                $this->events->dispatch(new PositionUpdated($position));
            }

            return $position;
        });
    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService\GetOppositePositionTest
     */
    public function getOppositePosition(Position $position): ?Position
    {
        return $this->getPosition($position->symbol, $position->side->getOpposite());
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
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService\AddStopTest
     */
    public function addConditionalStop(Position $position, Ticker $ticker, float $price, float $qty): string
    {
        return $this->positionService->addConditionalStop($position, $ticker, $price, $qty);
    }

    /**
     * @inheritDoc
     *
     * @throws CannotAffordOrderCost
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\CacheDecorated\ByBitLinearPositionCacheDecoratedService\AddBuyOrderTest
     */
    public function marketBuy(Position $position, Ticker $ticker, float $price, float $qty): string
    {
        return $this->positionService->marketBuy($position, $ticker, $price, $qty);
    }
}
