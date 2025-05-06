<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use LogicException;

final readonly class SettingAccessor
{
    public function __construct(
        public AppSettingInterface $setting,
        public ?Symbol $symbol = null,
        public ?Side $side = null
    ) {
        if ($this->side !== null && $this->symbol === null) {
            throw new LogicException('When $side specified Symbol must be specified too');
        }
    }

    public static function simple(AppSettingInterface $setting): self
    {
        return new self($setting);
    }

    public static function bySymbol(AppSettingInterface $setting, Symbol $symbol): self
    {
        return new self($setting, $symbol);
    }

    public static function bySide(AppSettingInterface $setting, Symbol $symbol, Side $side): self
    {
        return new self($setting, $symbol, $side);
    }

//    public function exact(): self
//    {
//
//    }

//    public function withAlternativesAllowed(): self
//    {
//
//    }
}
