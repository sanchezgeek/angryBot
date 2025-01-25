<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Position;

use App\Bot\Domain\ValueObject\Symbol;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use Symfony\Component\HttpFoundation\Request;

final readonly class SetLeverageRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/position/set-leverage';

    public function method(): string
    {
        return Request::METHOD_POST;
    }

    public function url(): string
    {
        return self::URL;
    }

    public function data(): array
    {
        return [
            'category' => $this->category->value,
            'symbol' => $this->symbol->value,
            'buyLeverage' => (string)$this->buyLeverage,
            'sellLeverage' => (string)$this->sellLeverage,
        ];
    }

    public function __construct(
        private AssetCategory $category,
        private Symbol $symbol,
        private float $buyLeverage,
        private float $sellLeverage,
    ) {
    }
}
