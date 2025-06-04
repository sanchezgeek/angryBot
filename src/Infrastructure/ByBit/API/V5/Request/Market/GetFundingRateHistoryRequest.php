<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Market;

use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Trading\Domain\Symbol\SymbolInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequestTest
 */
final readonly class GetFundingRateHistoryRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/market/funding/history';

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
        return ['category' => $this->category->value, 'symbol' => $this->symbol->name(), 'limit' => $this->limit];
    }

    public function __construct(private AssetCategory $category, private SymbolInterface $symbol, private int $limit = 1)
    {
    }
}
