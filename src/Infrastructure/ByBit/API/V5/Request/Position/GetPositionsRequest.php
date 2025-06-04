<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Position;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Worker\AppContext;
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

        if (!AppContext::isTest()) {
            $data['limit'] = 200;
        }

        if ($this->symbol) {
            $data['symbol'] = $this->symbol instanceof SymbolInterface ? $this->symbol->name() : $this->symbol;
        } else {
            $data['settleCoin'] = Coin::USDT->value;
        }

        return $data;
    }

    public function __construct(private AssetCategory $category, private SymbolInterface|string|null $symbol)
    {
    }
}
