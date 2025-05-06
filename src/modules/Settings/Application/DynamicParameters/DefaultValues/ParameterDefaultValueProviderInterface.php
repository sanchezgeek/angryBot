<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues;

interface ParameterDefaultValueProviderInterface
{
    public function get(array $input): mixed;
}
