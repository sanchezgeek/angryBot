<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\Service\CacheDecorated;

use App\Bot\Application\Events\Exchange\TickerUpdated;
use App\Bot\Application\Events\Exchange\TickerUpdateSkipped;
use App\Bot\Application\Service\Exchange\Exchange\InstrumentInfoDto;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Price\PriceRange;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Exception\ApiRateLimitReached;
use App\Infrastructure\ByBit\API\Common\Exception\UnknownByBitApiErrorException;
use App\Infrastructure\ByBit\Service\ByBitLinearExchangeService;
use App\Infrastructure\ByBit\Service\CacheDecorated\Dto\CachedTickerDto;
use App\Infrastructure\ByBit\Service\Exception\Market\TickerNotFoundException;
use App\Infrastructure\ByBit\Service\Exception\UnexpectedApiErrorException;
use App\Infrastructure\Cache\TickersCache;
use App\Worker\AppContext;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class ByBitLinearExchangeCacheDecoratedService implements ExchangeServiceInterface, TickersCache
{
    private const AssetCategory ASSET_CATEGORY = AssetCategory::linear;
    private const string DEFAULT_TICKER_TTL = '1000 milliseconds';

    private ?CacheInterface $externalCache;

    /**
     * @param ByBitLinearExchangeService $exchangeService
     * @param ?CacheInterface $externalCache
     */
    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private EventDispatcherInterface $events,
        private CacheInterface $localCache,
        ?CacheInterface $externalCache = null  # mixed - to not autowire in test env (@see services_test.yaml)
    ) {
        if (AppContext::isExternalTickersCacheEnabled()) {
            $this->externalCache = $externalCache;
        } else {
            $this->externalCache = null;
        }
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
    public function ticker(SymbolInterface $symbol): Ticker
    {
        if ($this->externalCache) {
            $itemFromExternal = $this->getTickerCacheItemFromExternalCache($symbol);
            if (
                $itemFromExternal->isHit()
                && ($cachedTickerDto = $itemFromExternal->get())
                && $cachedTickerDto->updatedByAccName !== self::thisAccName()
            ) {
                if ($symbol !== SymbolEnum::BTCUSDT) {
                    /** @var CachedTickerDto $cachedTickerDto */
                    $this->events->dispatch(new TickerUpdateSkipped($cachedTickerDto));
                }
                return $cachedTickerDto->ticker;
            }
        }

        $item = $this->localCache->getItem($this->tickerCacheKey($symbol));

        if ($item->isHit()) {
            $cachedTickerDto = $item->get(); /** @var CachedTickerDto $cachedTickerDto */
            return $cachedTickerDto->ticker;
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
    private function updateTicker(SymbolInterface $symbol, \DateInterval $ttl): Ticker
    {
        $key = $this->tickerCacheKey($symbol);

        $ticker = $this->exchangeService->ticker($symbol);
        $itemValue = new CachedTickerDto($ticker, self::thisAccName());
        $item = $this->localCache->getItem($key)->set($itemValue)->expiresAfter($ttl);

        $this->localCache->save($item);

        if ($this->externalCache) {
            $itemFromExternalCache = $this->getTickerCacheItemFromExternalCache($symbol);
            /** @var CachedTickerDto $itemFromExternalCacheValue */
            if (
                !$itemFromExternalCache->isHit() || (
                    ($itemFromExternalCacheValue = $itemFromExternalCache->get())
                    && $itemFromExternalCacheValue->updatedByAccName === self::thisAccName()
                )
            ) {
                $item = $this->externalCache->getItem($key)->set($itemValue)->expiresAfter(\DateInterval::createFromDateString('2 seconds'));
                $this->externalCache->save($item);
            }
        }

        $this->events->dispatch(new TickerUpdated($ticker));

        return $ticker;
    }

    /**
     * @throws TickerNotFoundException
     *
     * @throws ApiRateLimitReached
     * @throws UnexpectedApiErrorException
     * @throws UnknownByBitApiErrorException
     */
    public function checkExternalTickerCacheOrUpdate(SymbolInterface $symbol, \DateInterval $ttl): void
    {
        /** @var CachedTickerDto $itemFromExternalCacheValue */
        if (
            ($itemFromExternalCache = $this->getTickerCacheItemFromExternalCache($symbol)) &&
            $itemFromExternalCache->isHit() &&
            ($itemFromExternalCacheValue = $itemFromExternalCache->get())
            && $itemFromExternalCacheValue->updatedByAccName !== self::thisAccName()
        ) {
            $this->events->dispatch(new TickerUpdateSkipped($itemFromExternalCacheValue));
            return;
        }

        $this->updateTicker($symbol, $ttl);
    }

    private function getTickerCacheItemFromExternalCache(SymbolInterface $symbol): ?CacheItem
    {
        return $this->externalCache?->getItem($this->tickerCacheKey($symbol));
    }

    private static function thisAccName(): string
    {
        return AppContext::accName() ?? 'none';
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
    public function activeConditionalOrders(?SymbolInterface $symbol = null, ?PriceRange $priceRange = null): array
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

    public function getInstrumentInfo(SymbolInterface|string $symbol): InstrumentInfoDto
    {
        return $this->exchangeService->getInstrumentInfo($symbol);
    }

    private function tickerCacheKey(SymbolInterface $symbol): string
    {
        return \sprintf('api_%s_%s_ticker', self::ASSET_CATEGORY->value, $symbol->value);
    }
}
