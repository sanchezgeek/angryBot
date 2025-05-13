<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Attribute;

use App\Settings\Application\DynamicParameters\DefaultValues\DefaultValueProviderEnum;
use Attribute;

#[Attribute]
final class AppDynamicParameterEvaluations
{
    public function __construct(public DefaultValueProviderEnum $defaultValueProvider, public bool $skipUserInput = false)
    {
    }
}
