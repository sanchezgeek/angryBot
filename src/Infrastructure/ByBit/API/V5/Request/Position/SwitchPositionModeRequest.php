<?php

declare(strict_types=1);

namespace App\Infrastructure\ByBit\API\V5\Request\Position;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\Common\Request\AbstractByBitApiRequest;
use App\Infrastructure\ByBit\API\V5\Enum\Position\PositionMode;
use Symfony\Component\HttpFoundation\Request;

final readonly class SwitchPositionModeRequest extends AbstractByBitApiRequest
{
    public const URL = '/v5/position/switch-mode';

    public const SINGLE_MODE = 0;
    public const BOTH_SIDES_MODE = 3;

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
        $mode = match ($this->mode) {
            PositionMode::SINGLE_SIDE_MODE => self::SINGLE_MODE,
            PositionMode::BOTH_SIDES_MODE => self::BOTH_SIDES_MODE,
        };

        return [
            'category' => $this->category->value,
            'symbol' => $this->symbol->value,
            'mode' => $mode,
        ];
    }

    public function __construct(
        private AssetCategory $category,
        private SymbolInterface $symbol,
        private PositionMode $mode
    ) {
    }
}
