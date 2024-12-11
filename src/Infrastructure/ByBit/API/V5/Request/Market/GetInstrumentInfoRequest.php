<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Market;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

/**
 * @see https://bybit-exchange.github.io/docs/v5/market/instrument
 */
final readonly class GetInstrumentInfoRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/market/instruments-info';

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
