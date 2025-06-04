<?php

declare(strict_types=1);

namespace App\Settings\Application\Service\Restore;

use App\Domain\Position\ValueObject\Side;
use App\Settings\Domain\Entity\SettingValue;
use App\Trading\Application\Symbol\Exception\SymbolNotFoundException;
use App\Trading\Application\Symbol\SymbolProvider;

final readonly class SettingValueRestoreFactory
{
    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

    /**
     * @throws SymbolNotFoundException
     */
    public function restore(array $data): SettingValue
    {
        return SettingValue::withValue(
            $data['key'],
            $data['value'],
            $data['symbol'] !== null ? $this->symbolProvider->getOneByName($data['symbol']) : null,
            $data['positionSide'] !== null ? Side::from($data['positionSide']) : null,
        );
    }
}
