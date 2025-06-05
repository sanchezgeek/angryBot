<?php

declare(strict_types=1);

namespace App\Bot\Application\Service\RestoreOrder;

use App\Bot\Domain\Entity\Stop;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolException;

final readonly class StopRestoreFactory
{
    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    /**
     * @throws InitializeSymbolException
     * @throws SymbolEntityNotFoundException
     */
    public function restore(array $data): Stop
    {
        return new Stop(
            $data['id'],
            $data['price'],
            $data['volume'],
            $data['triggerDelta'],
            $this->symbolProvider->getOrInitialize($data['symbol']),
            Side::from($data['positionSide']),
            $data['context']
        );
    }
}
