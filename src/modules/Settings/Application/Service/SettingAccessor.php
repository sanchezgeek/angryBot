<?php

declare(strict_types=1);

namespace App\Settings\Application\Service;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use App\Settings\Application\Storage\AssignedSettingValueFactory;
use LogicException;
use Stringable;

final readonly class SettingAccessor implements Stringable
{
    public function __construct(
        public AppSettingInterface $setting,
        public ?Symbol $symbol = null,
        public ?Side $side = null,
        public bool $exact = false
    ) {
        if ($this->side !== null && $this->symbol === null) {
            throw new LogicException('When $side specified Symbol must be specified too');
        }
    }

    public static function withAlternativesAllowed(AppSettingInterface $setting, ?Symbol $symbol = null, ?Side $side = null): self
    {
        return new self($setting, $symbol, $side, false);
    }

    public static function exact(AppSettingInterface $setting, ?Symbol $symbol = null, ?Side $side = null): self
    {
        return new self($setting, $symbol, $side, true);
    }

    public function __toString(): string
    {
        return AssignedSettingValueFactory::buildFullKey($this->setting, $this->symbol, $this->side);
    }
}
