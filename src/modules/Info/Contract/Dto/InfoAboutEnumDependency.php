<?php

declare(strict_types=1);

namespace App\Info\Contract\Dto;

use BackedEnum;

final class InfoAboutEnumDependency extends AbstractDependencyInfo
{
    /**
     * @param string $dependentTarget
     * @param class-string<BackedEnum> $usedEnum
     * @param string|array $info
     * @param string|null $parametersGroup
     * @param string|null $parameterName
     */
    private function __construct(
        public string $dependentTarget,
        public string $usedEnum,
        string|array $info,
        public ?string $parametersGroup = null,
        public ?string $parameterName = null,
    ) {
        parent::__construct($info, $usedEnum);
    }

    /**
     * @param class-string<BackedEnum> $usedEnum
     */
    public static function create(
        string $target,
        string $usedEnum,
        string|array $info,
        ?string $parametersGroup = null,
        ?string $parameterName = null,
    ): self {
        return new self($target, $usedEnum, $info, $parametersGroup, $parameterName);
    }
}
