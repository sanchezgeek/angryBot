<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Position;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Position\GetPositionsRequestTest
 *
 * @see https://bybit-exchange.github.io/docs/v5/position
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
        $data = ['category' => $this->category->value];

        if ($this->symbol) {
            $data['symbol'] = $this->symbol instanceof Symbol ? $this->symbol->value : $this->symbol;
        } else {
            $data['settleCoin'] = Symbol::BTCUSDT->associatedCoin()->value;
        }

        return $data;
    }

    public function __construct(private AssetCategory $category, private Symbol|string|null $symbol)
    {
    }
}
