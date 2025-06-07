<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition\OrdersGrids;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Settings\OpenPositionSettings;
use App\Trading\Application\UseCase\OpenPosition\Exception\DefaultGridDefinitionNotFound;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class OpenPositionStopsGridsDefinitions
{
    private const OpenPositionSettings SETTING = OpenPositionSettings::Stops_DefaultGridDefinition;

    public function __construct(
        private AppSettingsProviderInterface $settings,
    ) {
    }

    /**
     * @throws DefaultGridDefinitionNotFound
     */
    public function create(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
    {
        $symbolSideDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $positionSide));
        $symbolDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol));

        if (!$symbolSideDef && !$symbolDef) {
            throw new DefaultGridDefinitionNotFound(
                sprintf('Cannot find predefined Stops grids definition nor for "%s", neither for "%s %s"', $symbol->name(), $symbol->name(), $positionSide->title())
            );
        }

        return OrdersGridDefinitionCollection::create($symbolSideDef ?? $symbolDef, $priceToRelate, $positionSide, $symbol);
    }
}
