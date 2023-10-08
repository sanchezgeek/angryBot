<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit;

use App\Bot\Application\Events\Exchange\PositionUpdated;
use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Polyfill\Intl\Icu\Exception\NotImplementedException;

final readonly class ByBitLinearExchangeCacheDecoratedService implements TickersCache
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    private const DEFAULT_TICKER_TTL = '1000 milliseconds';

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private EventDispatcherInterface $events,
        private CacheInterface $cache,
    ) {
    }

    public function ticker(Symbol $symbol): Ticker
    {
        $item = $this->cache->getItem(
            $this->tickerCacheKey($symbol)
        );

        if ($item->isHit()) {
            return $item->get();
        }

        return $this->updateTicker($symbol, \DateInterval::createFromDateString(self::DEFAULT_TICKER_TTL));
    }

    public function updateTicker(Symbol $symbol, \DateInterval $ttl): Ticker
    {
        $key = $this->tickerCacheKey($symbol);

        $ticker = $this->exchangeService->ticker($symbol);

        $item = $this->cache->getItem($key)->set($ticker)->expiresAfter($ttl);

        $this->cache->save($item);

        $this->events->dispatch(new TickerUpdated($ticker));

        return $ticker;
    }

    /**
     * @return ActiveStopOrder[]
     */
    public function activeConditionalOrders(Symbol $symbol): array
    {
        return $this->exchangeService->activeConditionalOrders($symbol);
    }

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        $this->exchangeService->closeActiveConditionalOrder($order);
    }

    private function tickerCacheKey(Symbol $symbol): string
    {
        return \sprintf('%s_%s_ticker', self::ASSET_CATEGORY->value, $symbol->value);
    }
}
