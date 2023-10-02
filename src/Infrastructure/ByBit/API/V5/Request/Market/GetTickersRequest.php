<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Market;

use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequestTest
 */
final readonly class GetTickersRequest extends AbstractByBitApiRequest
{
    public function method(): string
    {
        return Request::METHOD_GET;
    }

    public function url(): string
    {
        return '/v5/market/tickers';
    }

    public function data(): array
    {
        return ['category' => $this->category, 'symbol' => $this->symbol];
    }

    public function __construct(
        private string $category,
        private string $symbol,
    ) {
    }
}
