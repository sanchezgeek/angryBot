<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Attribute;

use Attribute;

#[Attribute]
final class AppDynamicParameter
{
    public function __construct(public string $group, public ?string $name = null)
    {
    }
}
