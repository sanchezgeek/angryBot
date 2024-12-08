<?php

declare(strict_types=1);

namespace App\Tests\Stub\Bot;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\PriceRange;
use App\Infrastructure\Cache\TickersCache;
use Exception;

use function sprintf;

final class ExchangeServiceStub implements ExchangeServiceInterface, TickersCache
{
    public function ticker(Symbol $symbol): Ticker
    {
        throw new Exception(sprintf('%s::ticker not supported', ExchangeServiceInterface::class));
    }

    public function checkExternalTickerCacheOrUpdate(Symbol $symbol, \DateInterval $ttl): Ticker
    {
        throw new Exception(sprintf('%s::updateTicker not supported', ExchangeServiceInterface::class));
    }

    public function activeConditionalOrders(Symbol $symbol, ?PriceRange $priceRange = null): array
    {
        throw new Exception(sprintf('%s::activeConditionalOrders not supported', ExchangeServiceInterface::class));
    }

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        throw new Exception(sprintf('%s::closeActiveConditionalOrder not supported', ExchangeServiceInterface::class));
    }
}
