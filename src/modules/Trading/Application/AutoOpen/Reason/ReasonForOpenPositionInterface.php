<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Reason;

interface ReasonForOpenPositionInterface
{
    public function getStringInfo(): string;
}
