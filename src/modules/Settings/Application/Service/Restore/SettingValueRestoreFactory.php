<?php

declare(strict_types=1);

namespace App\Settings\Application\Service\Restore;

use App\Domain\Position\ValueObject\Side;
use App\Settings\Domain\Entity\SettingValue;
use App\Trading\Application\Symbol\SymbolProvider;

final readonly class SettingValueRestoreFactory
{
    public function __construct(
        private SymbolProvider $symbolProvider,
    ) {
    }

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
