<?php

declare(strict_types=1);

namespace App\Settings\Application\Attribute;

use App\Settings\Domain\Enum\SettingType;
use Attribute;
use BackedEnum;
use RuntimeException;

/**
 * @todo | settings | всё таки надо добавить допускаемые уровни для настройки
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class SettingParametersAttribute
{
    public function __construct(
        public ?SettingType $type = SettingType::String,
        public bool $nullable = false,
        public ?string $enumClass = null
    ) {
        assert(
            $this->type !== SettingType::Enum || $this->enumClass && is_a($this->enumClass, BackedEnum::class),
            new RuntimeException(sprintf('Provided $enumClass name must inherit %s (%s given)', BackedEnum::class, $this->enumClass))
        );
    }
}
