<?php

declare(strict_types=1);

namespace App\Info\Contract\Dto;

abstract class AbstractDependencyInfo
{
    public function __construct(
        public string $info,
        public string $dependentOn,
    ) {
    }
}
