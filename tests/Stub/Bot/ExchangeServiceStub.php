<?php

declare(strict_types=1);

namespace App\Tests\Stub\Bot;

use App\Bot\Application\Service\Exchange\Exchange\InstrumentInfoDto;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Price\PriceRange;
use App\Infrastructure\Cache\TickersCache;
use Exception;

use function sprintf;

final class ExchangeServiceStub implements ExchangeServiceInterface, TickersCache
{
    public function ticker(SymbolInterface $symbol): Ticker
    {
        throw new Exception(sprintf('%s::ticker not supported', ExchangeServiceInterface::class));
    }

    public function checkExternalTickerCacheOrUpdate(SymbolInterface $symbol, \DateInterval $ttl): Ticker
    {
        throw new Exception(sprintf('%s::updateTicker not supported', ExchangeServiceInterface::class));
    }

    public function activeConditionalOrders(?SymbolInterface $symbol = null, ?PriceRange $priceRange = null): array
    {
        throw new Exception(sprintf('%s::activeConditionalOrders not supported', ExchangeServiceInterface::class));
    }

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        throw new Exception(sprintf('%s::closeActiveConditionalOrder not supported', ExchangeServiceInterface::class));
    }

    public function getInstrumentInfo(string|SymbolInterface $symbol): InstrumentInfoDto
    {
        throw new Exception(sprintf('%s::closeActiveConditionalOrder not supported', ExchangeServiceInterface::class));
    }
}
