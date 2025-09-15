<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Result;

final class OpenPositionPrerequisiteCheckResult
{
    public function __construct(
        public bool $success,
        public string $source,
        public string $info,
        public bool $silent = false,
        public ?int $confidenceMultiplier = null
    ) {
    }
}
