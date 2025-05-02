<?php

declare(strict_types=1);

namespace App\Settings\Application\Service\Dto;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\SettingKeyAware;
use LogicException;

final readonly class SettingValueAccessor
{
    private function __construct(
        public SettingKeyAware $setting,
        public ?Symbol $symbol = null,
        public ?Side $side = null
    ) {
        if ($this->side !== null && $this->symbol === null) {
            throw new LogicException('When $side specified Symbol must be specified too');
        }
    }

    public static function simple(SettingKeyAware $setting): self
    {
        return new self($setting);
    }

    public static function bySymbol(SettingKeyAware $setting, Symbol $symbol): self
    {
        return new self($setting, $symbol);
    }

    public static function bySide(SettingKeyAware $setting, Symbol $symbol, Side $side): self
    {
        return new self($setting, $symbol, $side);
    }
}
