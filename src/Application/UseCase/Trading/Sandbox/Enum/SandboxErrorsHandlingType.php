<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox\Enum;

enum SandboxErrorsHandlingType
{
    case CollectAndContinue;
    case ThrowException;
}
