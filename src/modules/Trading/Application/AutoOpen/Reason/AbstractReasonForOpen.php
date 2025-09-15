<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Reason;

abstract class AbstractReasonForOpen
{
    abstract public function getStringInfo(): string;
}
