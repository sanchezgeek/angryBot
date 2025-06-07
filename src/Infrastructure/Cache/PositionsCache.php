<?php

namespace App\Infrastructure\Cache;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

interface PositionsCache
{
    /**
     * @todo всё таки надо подписаться на wss
     *       актуально для ситуации, когда выполнится conditionStop и MBH пытается проверить `buyIsSafe`, а кэш не сбросился
     */
    public function clearPositionsCache(SymbolInterface $symbol, Side $positionSide): void;
}
