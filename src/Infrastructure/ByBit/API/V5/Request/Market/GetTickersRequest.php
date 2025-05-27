<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Market;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\Coin;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use LogicException;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see \App\Tests\Unit\Infrastructure\ByBit\V5Api\Request\Market\GetTickersRequestTest
 *
 * @link https://bybit-exchange.github.io/docs/v5/market/tickers
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
        $data = [
            'category' => $this->category->value,
        ];

        if ($this->symbol) {
            $data['symbol'] = $this->symbol->value;
        } else {
            $data['settleCoin'] = $this->settleCoin->value;
        }

        return $data;
    }

    public function __construct(
        private AssetCategory $category,
        private ?Symbol $symbol = null,
        private ?Coin $settleCoin = null
    ) {
        if (!$this->symbol && !$this->settleCoin) {
            throw new LogicException('When $symbol not specified $settleCoin must be specified instead');
        }

        if ($this->symbol && $this->settleCoin) {
            throw new LogicException('When $symbol specified $settleCoin not used');
        }
    }
}
