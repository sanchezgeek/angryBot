<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage\Dto;

use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Contract\AppSettingInterface;
use BackedEnum;
use Stringable;

final readonly class AssignedSettingValue implements Stringable
{
    public function __construct(
        public AppSettingInterface $setting,
        public ?SymbolInterface $symbol,
        public ?Side $side,
        public string $fullKey,
        public mixed $value,
        public ?string $info = null
    ) {
    }

    public function isDisabled(): bool
    {
        return $this->value === null;
    }

    public function __toString(): string
    {
        $value = $this->value;

        return match (true) {
            $this->isDisabled() => 'disabled',
            is_object($value) && method_exists($value, '__toString') => (string)$value,
            $this->value instanceof BackedEnum => $this->value->value,
            default => var_export($value, true)
        };
    }

    public function isDefault(): bool
    {
        return str_contains($this->info, 'default');
    }

    public function isFallbackValue(): bool
    {
        return $this->fullKey === $this->setting->getSettingKey();
    }
}
