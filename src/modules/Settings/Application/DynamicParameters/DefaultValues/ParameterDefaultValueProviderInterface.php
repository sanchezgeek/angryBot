<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\DefaultValues;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
interface ParameterDefaultValueProviderInterface
{
    public function getRequiredKeys(): array;
    public function get(array $input): mixed;
}
