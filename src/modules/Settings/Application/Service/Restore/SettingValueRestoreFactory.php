<?php

declare(strict_types=1);

namespace App\Settings\Application\Service\Restore;

use App\Domain\Position\ValueObject\Side;
use App\Settings\Domain\Entity\SettingValue;
use App\Trading\Application\Symbol\Exception\SymbolEntityNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;
use App\Trading\Application\UseCase\Symbol\InitializeSymbols\InitializeSymbolException;

final readonly class SettingValueRestoreFactory
{
    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    /**
     * @throws SymbolEntityNotFoundException
     * @throws InitializeSymbolException
     */
    public function restore(array $data): SettingValue
    {
        return SettingValue::withValue(
            $data['key'],
            $data['value'],
            $data['symbol'] !== null ? $this->symbolProvider->getOrInitialize($data['symbol']) : null,
            $data['positionSide'] !== null ? Side::from($data['positionSide']) : null,
        );
    }
}
