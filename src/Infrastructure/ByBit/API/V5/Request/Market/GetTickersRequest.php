<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Market;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Asset\AssetCategory;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequestTest
 */
final readonly class GetTickersRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/market/tickers';

    public function method(): string
    {
        return Request::METHOD_GET;
    }

    public function url(): string
    {
        return self::URL;
    }

    public function isPrivateRequest(): bool
    {
        return false;
    }

    public function data(): array
    {
        return ['category' => $this->category->value, 'symbol' => $this->symbol->value];
    }

    public function __construct(private AssetCategory $category, private Symbol $symbol)
    {
    }
}
