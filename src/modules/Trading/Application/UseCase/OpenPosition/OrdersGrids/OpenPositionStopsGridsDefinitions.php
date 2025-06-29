<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition\OrdersGrids;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
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
        $shortBoundPriceChangePercent = $this->tradingParametersProvider->regularPredefinedStopLength($symbol, PredefinedStopLengthSelector::Standard)->value();
        $shortBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($shortBoundPriceChangePercent);
        $veryLongBoundPriceChangePercent = $this->tradingParametersProvider->regularPredefinedStopLength($symbol, PredefinedStopLengthSelector::VeryLong)->value();
        $veryLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($veryLongBoundPriceChangePercent);
        $diff = $veryLongBoundPnl - $shortBoundPnl;

        $longBoundPriceChangePercent = $this->tradingParametersProvider->regularPredefinedStopLength($symbol, PredefinedStopLengthSelector::Long)->value();
        $longBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($longBoundPriceChangePercent);

        $defs = [
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $shortBoundPnl, $shortBoundPnl + $diff),
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $longBoundPnl, $longBoundPnl + $diff),
        ];

        $collectionDef = implode(OrdersGridDefinitionCollection::SEPARATOR, $defs);
        $resultDef = OrdersGridDefinitionCollection::create($collectionDef, $priceToRelate, $positionSide, $symbol);

        return $resultDef->setFoundAutomaticallyFromTa();
    }
}
