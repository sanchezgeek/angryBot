<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\CacheDecorated;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\TickersCache;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\PriceRange;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Infrastructure\ByBit\Service\Exception\Market\TickerNotFoundException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class ByBitLinearExchangeCacheDecoratedService implements ExchangeServiceInterface, TickersCache
{
    private const ASSET_CATEGORY = AssetCategory::linear;

    private const DEFAULT_TICKER_TTL = '1000 milliseconds';

    /**
     * @param ByBitLinearExchangeService $exchangeService
     */
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private EventDispatcherInterface $events,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @throws TickerNotFoundException
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\ByBitLinearExchangeCacheDecoratedService\GetTickerTest
     */
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

    /**
     * @throws TickerNotFoundException
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     */
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
     *
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\ByBitLinearExchangeCacheDecoratedService\GetActiveConditionalOrdersTest
     */
    public function activeConditionalOrders(Symbol $symbol, ?PriceRange $priceRange = null): array
    {
        return $this->exchangeService->activeConditionalOrders($symbol, $priceRange);
    }

    /**
     * @throws ApiRateLimitReached
     * @throws UnknownByBitApiErrorException
     * @throws UnexpectedApiErrorException
     *
     * @see \App\Tests\Functional\Infrastructure\BybBit\Service\ByBitLinearExchangeService\ByBitLinearExchangeCacheDecoratedService\CloseActiveConditionalOrderTest
     */
    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        $this->exchangeService->closeActiveConditionalOrder($order);
    }

    private function tickerCacheKey(Symbol $symbol): string
    {
        return \sprintf('api_%s_%s_ticker', self::ASSET_CATEGORY->value, $symbol->value);
    }
}
