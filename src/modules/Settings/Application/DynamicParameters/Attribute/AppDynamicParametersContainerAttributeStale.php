<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Attribute;

use Attribute;

#[Attribute]
final class AppDynamicParametersContainerAttributeStale
{
    public function __construct(public bool $isAutowiredService)
    {
    }
}
