<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\V5Api\Request;

use App\Infrastructure\ByBit\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

final readonly class GetTickerRequest extends AbstractByBitApiRequest
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
