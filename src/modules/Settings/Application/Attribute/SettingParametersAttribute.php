<?php

declare(strict_types=1);

namespace App\Settings\Application\Attribute;

use App\Settings\Domain\Enum\SettingType;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class SettingParametersAttribute
{
    public function __construct(public ?SettingType $type = SettingType::String, public bool $nullable = false)
    {
    }
}
