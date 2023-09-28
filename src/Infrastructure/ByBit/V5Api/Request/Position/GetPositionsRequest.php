<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\V5Api\Request\Position;

use App\Infrastructure\ByBit\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Position\GetPositionsRequestTest
 */
final readonly class GetPositionsRequest extends AbstractByBitApiRequest
{
    public function method(): string
    {
        return Request::METHOD_GET;
    }

    public function url(): string
    {
        return '/v5/position/list';
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
