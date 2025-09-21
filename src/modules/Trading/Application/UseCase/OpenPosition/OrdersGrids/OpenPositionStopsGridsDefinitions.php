<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition\OrdersGrids;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Stop\Helper\PnlHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\RiskLevel;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Application\Settings\OpenPositionSettings;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Grid\Definition\OrdersGridTools;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class OpenPositionStopsGridsDefinitions
{
    private const OpenPositionSettings SETTING = OpenPositionSettings::Stops_DefaultGridDefinition;

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $tradingParametersProvider,
        private OrdersGridTools $ordersGridTools,
    ) {
    }

    public function forAutoOpen(
        SymbolInterface $symbol,
        Side $positionSide,
        SymbolPrice $priceToRelate,
        RiskLevel $riskLevel,
        ?string $fromPnlPercent = null,
    ): OrdersGridDefinitionCollection {
        $fromPnlPercent = $fromPnlPercent ?? 0;

        $defs = [
            sprintf('%.2f%%-very-short..%.2f%%-very-short-very-short|30%%|3', $fromPnlPercent, $fromPnlPercent),
            sprintf('%.2f%%-short..%.2f%%-short-very-short|25%%|3', $fromPnlPercent, $fromPnlPercent),
            sprintf('%.2f%%-very-short..%.2f%%-very-short-long|20%%|5', $fromPnlPercent, $fromPnlPercent),
        ];

        return $this->makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function basedOnRiskLevel(
        SymbolInterface $symbol,
        Side $positionSide,
        SymbolPrice $priceToRelate,
        RiskLevel $riskLevel,
        null|float|string $fromPnlPercent = null,
    ): OrdersGridDefinitionCollection {
        $symbolSideDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $positionSide));
        $symbolDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol));

        if ($symbolSideDef || $symbolDef) {
            return OrdersGridDefinitionCollection::create($symbolSideDef ?? $symbolDef, $priceToRelate, $positionSide, $symbol);
        }

        return match ($riskLevel) {
            RiskLevel::Aggressive => $this->aggressive($symbol, $positionSide, $priceToRelate, $fromPnlPercent),
            RiskLevel::Cautious => $this->cautious($symbol, $positionSide, $priceToRelate, $fromPnlPercent),
            RiskLevel::Conservative => $this->conservative($symbol, $positionSide, $priceToRelate, $fromPnlPercent),
        };
    }

    public function aggressive(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, ?string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $fromPnlPercent ?? 0;

        $defs = [
            sprintf('%.2f%%-standard..%.2f%%-short-(very-long-standard)|50%%|5', $fromPnlPercent, $fromPnlPercent),
            sprintf('%.2f%%-long..%.2f%%-long-(very-long-standard)|50%%|5', $fromPnlPercent, $fromPnlPercent),
        ];

        return $this->makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function conservative(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, null|float|string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $fromPnlPercent ?? 0;

        return $this->makeDefinition([
            sprintf('-standard+%.2f%%..-moderate-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
            sprintf('-standard+%.2f%%..-very-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
        ], $priceToRelate, $symbol, $positionSide);
    }

    public function cautious(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, ?string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $fromPnlPercent ?? sprintf('%s/2', PriceDistanceSelector::VeryVeryShort->toLossExpr());

        $defs = [
            sprintf('%.2f%%..-short+%.2f%%|50%%|5', $fromPnlPercent , $fromPnlPercent),
            sprintf('%.2f%%..-moderate-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
        ];

        return $this->makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    private function getBoundPnlPercent(SymbolInterface $symbol, PriceDistanceSelector $lengthSelector): float
    {
        $priceChangePercent = $this->tradingParametersProvider->transformLengthToPricePercent($symbol, $lengthSelector)->value();

        return PnlHelper::transformPriceChangeToPnlPercent($priceChangePercent);
    }

    private function makeDefinition(array $defs, SymbolPrice $priceToRelate, SymbolInterface $symbol, Side $positionSide): OrdersGridDefinitionCollection
    {
        foreach ($defs as $key => $def) {
            $defs[$key] = $this->ordersGridTools->transformToFinalPercentRangeDefinition($symbol, $def);
        }

        $collectionDef = implode(OrdersGridDefinitionCollection::SEPARATOR, $defs);
        $resultDef = OrdersGridDefinitionCollection::create($collectionDef, $priceToRelate, $positionSide, $symbol);

        return $resultDef->setFoundAutomaticallyFromTa();
    }
}
