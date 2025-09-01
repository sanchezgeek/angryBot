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
use InvalidArgumentException;

final readonly class OpenPositionStopsGridsDefinitions
{
    private const OpenPositionSettings SETTING = OpenPositionSettings::Stops_DefaultGridDefinition;

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private TradingParametersProviderInterface $tradingParametersProvider
    ) {
    }

    public function create(
        SymbolInterface $symbol,
        Side $positionSide,
        SymbolPrice $priceToRelate,
        TradingStyle $tradingStyle,
        null|float|string $fromPnlPercent = null,
    ): OrdersGridDefinitionCollection {
        $symbolSideDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol, $positionSide));
        $symbolDef = $this->settings->optional(SettingAccessor::exact(self::SETTING, $symbol));

        if ($symbolSideDef || $symbolDef) {
            return OrdersGridDefinitionCollection::create($symbolSideDef ?? $symbolDef, $priceToRelate, $positionSide, $symbol);
        }

        return match ($tradingStyle) {
            TradingStyle::Aggressive => $this->aggressive($symbol, $positionSide, $priceToRelate, $fromPnlPercent),
            TradingStyle::Cautious => $this->cautious($symbol, $positionSide, $priceToRelate, $fromPnlPercent),
            TradingStyle::Conservative => $this->conservative($symbol, $positionSide, $priceToRelate, $fromPnlPercent),
        };
    }

    public function aggressive(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, null|float|string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);

        $shortBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Standard)->value();
        $shortBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($shortBoundPriceChangePercent);
        $veryLongBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::VeryLong)->value();
        $veryLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($veryLongBoundPriceChangePercent);
        $diff = $veryLongBoundPnl - $shortBoundPnl;

        $longBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Long)->value();
        $longBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($longBoundPriceChangePercent);

        $defs = [
            sprintf('%.2f%%..-%.2f%%|50%%|5', -$shortBoundPnl + $fromPnlPercent, $shortBoundPnl + $diff - $fromPnlPercent),
            sprintf('%.2f%%..-%.2f%%|50%%|5', -$longBoundPnl + $fromPnlPercent, $longBoundPnl + $diff - $fromPnlPercent),
        ];

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function conservative(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, null|float|string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);

        $standardBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Standard)->value();
        $standardBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($standardBoundPriceChangePercent);

        $moderateLongBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::Long)->value();
        $moderateLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($moderateLongBoundPriceChangePercent);

        $veryLongBoundPriceChangePercent = $this->tradingParametersProvider->stopLength($symbol, PriceDistanceSelector::VeryLong)->value();
        $veryLongBoundPnl = PnlHelper::transformPriceChangeToPnlPercent($veryLongBoundPriceChangePercent);

        $defs = [
            sprintf('%.2f%%..-%.2f%%|50%%|5', -$standardBoundPnl + $fromPnlPercent, $moderateLongBoundPnl - $fromPnlPercent),
            sprintf('%.2f%%..-%.2f%%|50%%|5', -$standardBoundPnl + $fromPnlPercent, $veryLongBoundPnl - $fromPnlPercent),
        ];

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function cautious(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, null|float|string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        if ($fromPnlPercent === null) {
            $fromPnlPercent = PriceDistanceSelector::VeryVeryShort->toStringWithNegativeSign();
            $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);
            $fromPnlPercent /= 2;
        } else {
            $fromPnlPercent = $this->parseFromPnlPercent($symbol, $fromPnlPercent);
        }

        $positionEntry = 0;

        $shortPnlPercent = $this->getBoundPnlPercent($symbol, PriceDistanceSelector::Short);
        $moderateLongPnlPercent = $this->getBoundPnlPercent($symbol, PriceDistanceSelector::ModerateLong);

        $defs = [
            sprintf('%.2f%%..-%.2f%%|50%%|5', $positionEntry + $fromPnlPercent , $shortPnlPercent - $fromPnlPercent),
            sprintf('%.2f%%..-%.2f%%|50%%|5', $positionEntry + $fromPnlPercent, $moderateLongPnlPercent - $fromPnlPercent),
        ];

        return self::makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    private function getBoundPnlPercent(SymbolInterface $symbol, PriceDistanceSelector $lengthSelector): float
    {
        $priceChangePercent = $this->tradingParametersProvider->stopLength($symbol, $lengthSelector)->value();

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

    public static function makeDefinition(array $defs, SymbolPrice $priceToRelate, SymbolInterface $symbol, Side $positionSide): OrdersGridDefinitionCollection
    {
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
