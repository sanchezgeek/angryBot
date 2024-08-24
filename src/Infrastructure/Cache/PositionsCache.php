<?php

namespace App\Infrastructure\Cache;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;

interface PositionsCache
{
    /**
     * @todo всё таки надо подписаться на wss
     *       актуально для ситуации, когда выполнится conditionStop и MBH пытается проверить `buyIsSafe`, а кэш не сбросился
     */
    public function clearPositionsCache(Symbol $symbol, Side $positionSide): void;
}
