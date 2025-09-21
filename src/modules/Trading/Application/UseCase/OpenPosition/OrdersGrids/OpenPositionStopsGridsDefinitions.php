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
use InvalidArgumentException;

final readonly class OpenPositionStopsGridsDefinitions
{
    private const OpenPositionSettings SETTING = OpenPositionSettings::Stops_DefaultGridDefinition;

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $tradingParametersProvider,
        private OrdersGridTools $ordersGridTools,
    ) {
    }

    public function create(
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

    public function aggressive(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, null|float|string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);

        $shortBoundPriceChangePercent = $this->tradingParametersProvider->transformLengthToPricePercent($symbol, PriceDistanceSelector::Standard)->value();
        $shortBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($shortBoundPriceChangePercent);
        $veryLongBoundPriceChangePercent = $this->tradingParametersProvider->transformLengthToPricePercent($symbol, PriceDistanceSelector::VeryLong)->value();
        $veryLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($veryLongBoundPriceChangePercent);
        $diff = $veryLongBoundPnl - $shortBoundPnl;

        $modifier = $fromPnlPercent - $diff;
        $modifier = sprintf('%s%.2f%%', $modifier > 0 ? '+' : '-', abs($modifier));

        $defs = [
            sprintf('-standard+%.2f%%..-short%s|50%%|5', $fromPnlPercent, $modifier),
            sprintf('-long+%.2f%%..-long%s|50%%|5', $fromPnlPercent, $modifier),
        ];

        return $this->makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function conservative(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, null|float|string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);

        return $this->makeDefinition([
            sprintf('-standard+%.2f%%..-moderate-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
            sprintf('-standard+%.2f%%..-very-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
        ], $priceToRelate, $symbol, $positionSide);
    }

    public function cautious(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, null|float|string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        if ($fromPnlPercent === null) {
            $fromPnlPercent = PriceDistanceSelector::VeryVeryShort->toLossExpr();
            $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);
            $fromPnlPercent /= 2;
        } else {
            $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);
        }

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

    private function parseFromPnlPercent(SymbolInterface $symbol, null|float|string $fromPnlPercent): float
    {
        $fromPnlPercent = $fromPnlPercent ?? 0;

        if (is_string($fromPnlPercent)) {
            [$distance, $sign] = self::parseDistanceSelector($fromPnlPercent);
            $fromPnlPercent = $sign * $this->getBoundPnlPercent($symbol, $distance);
        }

        return $fromPnlPercent;
    }

    public function makeDefinition(array $defs, SymbolPrice $priceToRelate, SymbolInterface $symbol, Side $positionSide): OrdersGridDefinitionCollection
    {
        foreach ($defs as $key => $def) {
            $defs[$key] = $this->ordersGridTools->transformToFinalPercentRangeDefinition($symbol, $def);
        }

        $collectionDef = implode(OrdersGridDefinitionCollection::SEPARATOR, $defs);
        $resultDef = OrdersGridDefinitionCollection::create($collectionDef, $priceToRelate, $positionSide, $symbol);

        return $resultDef->setFoundAutomaticallyFromTa();
    }

    public static function parseDistanceSelector(string $distance): array
    {
        $sign = 1;
        if (str_starts_with($distance, '-')) {
            $sign = -1;
            $distance = substr($distance, 1);
        }

        if (!$parsed = PriceDistanceSelector::from($distance)) {
            throw new InvalidArgumentException('distance must be of type PriceDistanceSelector');
        }

        return [$parsed, $sign];
    }
}
