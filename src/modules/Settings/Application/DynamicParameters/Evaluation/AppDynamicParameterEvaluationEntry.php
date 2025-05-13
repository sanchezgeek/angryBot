<?php

declare(strict_types=1);

namespace App\Settings\Application\DynamicParameters\Evaluation;

final readonly class AppDynamicParameterEvaluationEntry
{
    public function __construct(
        public string $groupName,
        public string $parameterName,
        public array $methodArgumentsInput = [],
        public array $constructorArgumentsInput = [],
    ) {
    }
}
