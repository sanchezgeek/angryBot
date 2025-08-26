<?php

namespace App\Command\Helper;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final class UndoHelper
{
    public static function stopsUndoOutput(
        SymbolInterface $symbol,
        Side $positionSide,
        string $uniqueId,
    ): array {
        return [
            sprintf('Stops grid created. uniqueID: %s', $uniqueId),
            sprintf('For delete them just run:' . PHP_EOL . './bin/console sl:edit --symbol=%s %s -aremove --fC="getContext(\'uniqid\')===\'%s\'"', $symbol->name(), $positionSide->value, $uniqueId) . PHP_EOL . PHP_EOL
        ];
    }
}
