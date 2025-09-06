<?php

declare(strict_types=1);

namespace App\Info\Contract\Dto;

use BackedEnum;

final class InfoAboutEnumDependency extends AbstractDependencyInfo
{
    /**
     * @param string $dependentTarget
     * @param class-string<BackedEnum> $usedEnum
     * @param string $info
     */
    private function __construct(
        public string $dependentTarget,
        public string $usedEnum,
        string $info,
    ) {
        parent::__construct($info, $usedEnum);
    }

    /**
     * @param class-string<BackedEnum> $usedEnum
     */
    public static function create(string $target, string $usedEnum, string $info): self
    {
        return new self($target, $usedEnum, $info);
    }
}
