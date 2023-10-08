<?php

declare(strict_types=1);

namespace App\Tests\Stub\Bot;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Domain\Exchange\ActiveStopOrder;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use Symfony\Polyfill\Intl\Icu\Exception\NotImplementedException;

use function sprintf;

final class ExchangeServiceStub implements ExchangeServiceInterface
{
    public function ticker(Symbol $symbol): Ticker
    {
        throw new NotImplementedException(sprintf('%s::ticker not supported', ExchangeServiceInterface::class));
    }

    public function activeConditionalOrders(Symbol $symbol): array
    {
        throw new NotImplementedException(sprintf('%s::activeConditionalOrders not supported', ExchangeServiceInterface::class));
    }

    public function closeActiveConditionalOrder(ActiveStopOrder $order): void
    {
        throw new NotImplementedException(sprintf('%s::closeActiveConditionalOrder not supported', ExchangeServiceInterface::class));
    }
}
