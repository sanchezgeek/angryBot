<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\RestoreOrder;

use App\Bot\Domain\Entity\Stop;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Application\Symbol\SymbolProvider;

final readonly class StopRestoreFactory
{
    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    public function restore(array $data, bool $restoreId = false): Stop
    {
        // @todo | check if sequence rewinds to and move back to restore also id

        return new Stop(
            $restoreId ? $data['id'] : null,
            $data['price'],
            $data['volume'],
            $data['triggerDelta'],
            $this->symbolProvider->getOrInitialize($data['symbol']),
            Side::from($data['positionSide']),
            $data['context']
        );
    }
}
