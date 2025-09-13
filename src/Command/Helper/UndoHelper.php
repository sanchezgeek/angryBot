<?php

namespace App\Command\Helper;

use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;

final class UndoHelper
{
    public static function stopsUndoOutput(
        string $uniqueId,
        ?SymbolInterface $symbol = null,
        ?Side $positionSide = null,
    ): array {
        return [
            sprintf('Stops grid created. uniqueID: %s', $uniqueId),
            sprintf(
                'For delete them just run:' . PHP_EOL . './bin/console sl:edit %s %s -aremove --fC="getContext(\'uniqid\')===\'%s\'"',
                $symbol ? sprintf(' --symbol=%s', $symbol->name()) : '',
                $positionSide ? $positionSide->value : '',
                $uniqueId
            ) . PHP_EOL . PHP_EOL
        ];
    }
}
