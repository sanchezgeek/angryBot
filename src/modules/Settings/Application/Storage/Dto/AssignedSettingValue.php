<?php

declare(strict_types=1);

namespace App\Settings\Application\Storage\Dto;

use App\Settings\Application\Contract\SettingKeyAware;

final readonly class AssignedSettingValue
{
    public function __construct(
        public SettingKeyAware $setting,
        public string $fullKey,
        public mixed $value,
        public ?string $info = null
    ) {
    }

    public function isDisabled(): bool
    {
        return $this->value === null;
    }
}
