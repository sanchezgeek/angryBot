<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

use App\Bot\Application\Exception\ApiRateLimitReached;
use App\Bot\Application\Exception\CannotAffordOrderCost;
use App\Bot\Application\Exception\MaxActiveCondOrdersQntReached;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use Lin\Bybit\BybitLinear;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class ByBitPositionService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly CacheInterface $cache,
        private readonly EventDispatcherInterface $events,
    ) {
//        $this->api = new BybitLinear($this->apiKey, $this->apiSecret, self::URL);
    }

    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        // TODO: Implement getPosition() method.
    }

    public function getOppositePosition(Position $position): ?Position
    {
        // TODO: Implement getOppositePosition() method.
    }

    public function addStop(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        // TODO: Implement addStop() method.
    }

    public function addBuyOrder(Position $position, Ticker $ticker, float $price, float $qty): ?string
    {
        // TODO: Implement addBuyOrder() method.
    }
}
