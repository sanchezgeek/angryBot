<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition\OrdersGrids;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\TradingStyle;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\OpenPositionSettings;
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

    public function create(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, TradingStyle $tradingStyle): OrdersGridDefinitionCollection
    {
        $symbolSideDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $positionSide));
        $symbolDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol));

        if ($symbolSideDef || $symbolDef) {
            return OrdersGridDefinitionCollection::create($symbolSideDef ?? $symbolDef, $priceToRelate, $positionSide, $symbol);
        }

        return match ($tradingStyle) {
            TradingStyle::Aggressive => $this->aggressive($symbol, $positionSide, $priceToRelate),
            TradingStyle::Cautious => $this->cautious($symbol, $positionSide, $priceToRelate),
            TradingStyle::Conservative => $this->conservative($symbol, $positionSide, $priceToRelate),
        };
    }

    public function aggressive(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
    {
        $shortBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Standard)->value();
        $shortBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($shortBoundPriceChangePercent);
        $veryLongBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::VeryLong)->value();
        $veryLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($veryLongBoundPriceChangePercent);
        $diff = $veryLongBoundPnl - $shortBoundPnl;

        $longBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Long)->value();
        $longBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($longBoundPriceChangePercent);

        $defs = [
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $shortBoundPnl, $shortBoundPnl + $diff),
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $longBoundPnl, $longBoundPnl + $diff),
        ];

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function conservative(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
    {
        $standardBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Standard)->value();
        $standardBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($standardBoundPriceChangePercent);

        $moderateLongBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Long)->value();
        $moderateLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($moderateLongBoundPriceChangePercent);

        $veryLongBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::VeryLong)->value();
        $veryLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($veryLongBoundPriceChangePercent);

        $defs = [
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $standardBoundPnl, $moderateLongBoundPnl),
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $standardBoundPnl, $veryLongBoundPnl),
        ];

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function cautious(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate): OrdersGridDefinitionCollection
    {
        $positionEntry = 0;

        $shortPnlPercent = $this->getBoundPnlPercent($symbol, PriceDistanceSelector::Short);
        $moderateLongPnlPercent = $this->getBoundPnlPercent($symbol, PriceDistanceSelector::ModerateLong);

        $defs = [
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $positionEntry, $shortPnlPercent),
            sprintf('-%.2f%%..-%.2f%%|50%%|5', $positionEntry, $moderateLongPnlPercent),
        ];

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    private function getBoundPnlPercent(SymbolInterface $symbol, PriceDistanceSelector $lengthSelector): float
    {
        $priceChangePercent = $this->tradingParametersProvider->stopLength($symbol, $lengthSelector)->value();

        return PnlHelper::transformPriceChangeToPnlPercent($priceChangePercent);
    }

    private static function makeDefinition(array $defs, SymbolPrice $priceToRelate, SymbolInterface $symbol, Side $positionSide): OrdersGridDefinitionCollection
    {
        $collectionDef = implode(OrdersGridDefinitionCollection::SEPARATOR, $defs);
        $resultDef = OrdersGridDefinitionCollection::create($collectionDef, $priceToRelate, $positionSide, $symbol);

        return $resultDef->setFoundAutomaticallyFromTa();
    }

}
