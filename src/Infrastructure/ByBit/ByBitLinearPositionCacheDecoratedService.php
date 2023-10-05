<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class ByBitLinearPositionCacheDecoratedService implements PositionServiceInterface
{
    /** @todo | inject into service? */
    private const POSITION_TTL = '6 seconds';

    public function __construct(
        private PositionServiceInterface $positionService,
        private EventDispatcherInterface $events,
        private CacheInterface $cache,
    ) {

    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionCacheDecoratedService\GetPositionTest
     */
    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        $key = \sprintf('apiV5_position_data_%s_%s', $symbol->value, $side->value);

        return $this->cache->get($key, function (ItemInterface $item) use ($symbol, $side) {
            $item->expiresAfter(\DateInterval::createFromDateString(self::POSITION_TTL));

            if ($position = $this->positionService->getPosition($symbol, $side)) {
                $this->events->dispatch(new PositionUpdated($position));
            }

            return $position;
        });
    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionCacheDecoratedService\GetOppositePositionTest
     */
    public function getOppositePosition(Position $position): ?Position
    {
        return $this->getPosition($position->symbol, $position->side->getOpposite());
    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionCacheDecoratedService\AddStopTest
     */
    public function addStop(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        return $this->positionService->addStop($position, $ticker, $price, $qty);
    }

    /**
     * @see \App\Tests\Functional\Infrastructure\BybBit\ByBitLinearPositionCacheDecoratedService\AddBuyOrderTest
     */
    public function addBuyOrder(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        return $this->positionService->addBuyOrder($position, $ticker, $price, $qty);
    }
}
