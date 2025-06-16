<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Asset\History;

use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequestTest
 *
 * @link https://bybit-exchange.github.io/docs/v5/market/kline
 */
final readonly class GetHistoryRequest extends AbstractByBitApiRequest
{
//    public const string URL = '/v5/order/history';
    public const URL = '/v5/account/transaction-log';

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
        return true;
    }

    public function data(): array
    {
        return [
            'category' => $this->category->value,
            'limit' => 100,
        ];
    }

    public function __construct(
        private AssetCategory $category = AssetCategory::linear,
    ) {
    }
}
