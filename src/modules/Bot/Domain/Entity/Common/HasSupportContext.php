<?php

declare(strict_types=1);

namespace App\Bot\Domain\Entity\Common;

trait HasSupportContext
{
    public function isSupportFromMainHedgePositionStopOrder(): bool
    {
        return ($this->context['asSupportFromMainHedgePosition'] ?? null) === true;
    }
}
