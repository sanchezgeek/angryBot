<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition\OrdersGrids;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PredefinedStopLengthSelector;
use App\Domain\Trading\Enum\TradingStyle;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\OpenPositionSettings;
use App\Trading\Application\UseCase\OpenPosition\Exception\DefaultGridDefinitionNotFound;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Symbol\SymbolInterface;
use RuntimeException;

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
    public function create(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, TradingStyle $tradingStyle): OrdersGridDefinitionCollection
    {
        $symbolSideDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $positionSide));
        $symbolDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol));

        if ($symbolSideDef || $symbolDef) {
            return OrdersGridDefinitionCollection::create($symbolSideDef ?? $symbolDef, $priceToRelate, $positionSide, $symbol);
        }

        return match ($tradingStyle) {
            TradingStyle::Aggressive => $this->aggressive($symbol, $positionSide, $priceToRelate),
            TradingStyle::Cautious => throw new RuntimeException(sprintf('%s not implemented yet', TradingStyle::Conservative->value)),
            TradingStyle::Conservative => $this->conservative($symbol, $positionSide, $priceToRelate),
        };
    }

    public function aggressive(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
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

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function conservative(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
    {
        $standardBoundPriceChangePercent = $this->tradingParametersProvider->regularPredefinedStopLength($symbol, PredefinedStopLengthSelector::Standard)->value();
        $standardBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($standardBoundPriceChangePercent);

        $moderateLongBoundPriceChangePercent = $this->tradingParametersProvider->regularPredefinedStopLength($symbol, PredefinedStopLengthSelector::Long)->value();
        $moderateLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($moderateLongBoundPriceChangePercent);

        $veryLongBoundPriceChangePercent = $this->tradingParametersProvider->regularPredefinedStopLength($symbol, PredefinedStopLengthSelector::VeryLong)->value();
        $veryLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($veryLongBoundPriceChangePercent);

        $defs = [
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $standardBoundPnl, $moderateLongBoundPnl),
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $standardBoundPnl, $veryLongBoundPnl),
        ];

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    private static function makeDefinition(array $defs, SymbolPrice $priceToRelate, SymbolInterface $symbol, Side $positionSide): OrdersGridDefinitionCollection
    {
        $collectionDef = implode(OrdersGridDefinitionCollection::SEPARATOR, $defs);
        $resultDef = OrdersGridDefinitionCollection::create($collectionDef, $priceToRelate, $positionSide, $symbol);

        return $resultDef->setFoundAutomaticallyFromTa();
    }

}
