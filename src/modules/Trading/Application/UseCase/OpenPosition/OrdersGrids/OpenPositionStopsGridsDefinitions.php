<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition\OrdersGrids;

use App\Buy\Domain\Enum\PredefinedStopLengthSelector;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\OpenPositionSettings;
use App\Trading\Application\UseCase\OpenPosition\Exception\DefaultGridDefinitionNotFound;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class OpenPositionStopsGridsDefinitions
{
    private const OpenPositionSettings SETTING = OpenPositionSettings::Stops_DefaultGridDefinition;

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $tradingParametersProvider
    ) {
    }

    /**
     * @throws DefaultGridDefinitionNotFound
     */
    public function create(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
    {
        $symbolSideDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $positionSide));
        $symbolDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol));

        if ($symbolSideDef || $symbolDef) {
            return OrdersGridDefinitionCollection::create($symbolSideDef ?? $symbolDef, $priceToRelate, $positionSide, $symbol);
        }

        return $this->byTa($symbol, $positionSide, $priceToRelate);
    }

    public function byTa(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
    {
        $shortBound = $this->tradingParametersProvider->regularPredefinedStopLengthPercent($symbol, PredefinedStopLengthSelector::Standard)->value();
        $veryLongBound = $this->tradingParametersProvider->regularPredefinedStopLengthPercent($symbol, PredefinedStopLengthSelector::VeryLong)->value();
        $diff = $veryLongBound - $shortBound;

        $longBound = $this->tradingParametersProvider->regularPredefinedStopLengthPercent($symbol, PredefinedStopLengthSelector::Long)->value();

        $defs = [
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $shortBound * 100, ($shortBound + $diff) * 100),
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $longBound * 100, ($longBound + $diff) * 100),
        ];

        $collectionDef = implode(OrdersGridDefinitionCollection::SEPARATOR, $defs);
        $resultDef = OrdersGridDefinitionCollection::create($collectionDef, $priceToRelate, $positionSide, $symbol);

        return $resultDef->setFoundAutomaticallyFromTa();
    }
}
