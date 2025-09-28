<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\OpenPosition\OrdersGrids;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\SettingAccessor;
use App\Trading\Application\Settings\OpenPositionSettings;
use App\Trading\Domain\Grid\Definition\OrdersGridDefinitionCollection;
use App\Trading\Domain\Grid\Definition\OrdersGridTools;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class OpenPositionStopsGridsDefinitions
{
    private const OpenPositionSettings SETTING = OpenPositionSettings::Stops_DefaultGridDefinition;

    public function __construct(
        private AppSettingsProviderInterface $settings,
        private OrdersGridTools $ordersGridTools,
        private PositionServiceInterface $positionService,
    ) {
    }

    public function standard(
        SymbolInterface $symbol,
        Side $positionSide,
        SymbolPrice|float $priceToRelate,
        RiskLevel $riskLevel,
        null|float|string $fromPnlPercent = null,
    ): OrdersGridDefinitionCollection {
        $fromPnlPercent = $fromPnlPercent ?? 0;
        $priceToRelate = $priceToRelate instanceof SymbolPrice ? $priceToRelate : $symbol->makePrice($priceToRelate);

        $defs = match ($riskLevel) {
            default => [
                sprintf('%.2f%%-%s..%.2f%%-%s-%s|30%%|3', $fromPnlPercent, Length::VeryShort->value, $fromPnlPercent, Length::VeryShort->value, Length::VeryShort->value),
                sprintf('%.2f%%-%s..%.2f%%-%s-%s|25%%|3', $fromPnlPercent, Length::Short->value, $fromPnlPercent, Length::Short->value, Length::VeryShort->value),
                sprintf('%.2f%%-%s..%.2f%%-%s-%s|20%%|5', $fromPnlPercent, Length::VeryShort->value, $fromPnlPercent, Length::VeryShort->value, Length::Long->value), // same
            ],
            RiskLevel::Cautious => [
                sprintf('%.2f%%-very-very-short..%.2f%%-very-very-short-very-very-short|30%%|3', $fromPnlPercent, $fromPnlPercent),
                sprintf('%.2f%%-very-short..%.2f%%-very-short-very-very-short|25%%|3', $fromPnlPercent, $fromPnlPercent),
                sprintf('%.2f%%-very-short..%.2f%%-very-short-long|20%%|5', $fromPnlPercent, $fromPnlPercent), // same
            ],
            RiskLevel::Aggressive => [
                sprintf('%.2f%%-%s..%.2f%%-%s-%s|30%%|3', $fromPnlPercent, Length::Short->value, $fromPnlPercent, Length::Short->value, Length::Short->value),
                sprintf('%.2f%%-%s..%.2f%%-%s-%s|25%%|3', $fromPnlPercent, Length::BetweenShortAndStd->value, $fromPnlPercent, Length::BetweenShortAndStd->value, Length::Short->value),
                sprintf('%.2f%%-%s..%.2f%%-%s-%s|20%%|5', $fromPnlPercent, Length::Short->value, $fromPnlPercent, Length::Short->value, Length::Long->value), // almost same =)
            ],
        };

        return $this->makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function fixation(
        SymbolInterface $symbol,
        Side $positionSide,
        RiskLevel $riskLevel,
        SymbolPrice $priceToRelate,
        ?Percent $pnlPercentFromPosition = null,
        null|float|string $fromPnlPercent = null,
        int $stopsCount = 10,
    ): OrdersGridDefinitionCollection {
        $fromPnlPercent = $fromPnlPercent ?? Length::AlmostImmideately->toLossExpr();
        $pnlPercentFromPosition = $pnlPercentFromPosition ? $pnlPercentFromPosition->value() : 0;

        $position = $this->positionService->getPosition($symbol, $positionSide);
        $toPrice = $position->entryPrice()->getTargetPriceByPnlPercent($pnlPercentFromPosition, $positionSide);

        $toPnlPercent = $priceToRelate->differenceWith($toPrice)->percentDeltaForPositionLoss($positionSide);

        $defs = [
            sprintf('%s..%s|100%%|%d', $fromPnlPercent, $toPnlPercent, $stopsCount),
        ];

        return $this->makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
    }

    public function deprecated(
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

    public function conservative(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, ?string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $fromPnlPercent ?? 0;

        return $this->makeDefinition([
            sprintf('-standard+%.2f%%..-moderate-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
            sprintf('-standard+%.2f%%..-very-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
        ], $priceToRelate, $symbol, $positionSide);
    }

    public function cautious(SymbolInterface $symbol, Side $positionSide, SymbolPrice $priceToRelate, ?string $fromPnlPercent): OrdersGridDefinitionCollection
    {
        $fromPnlPercent = $fromPnlPercent ?? sprintf('%s/2', Length::VeryVeryShort->toLossExpr());

        $defs = [
            sprintf('%.2f%%..-short+%.2f%%|50%%|5', $fromPnlPercent , $fromPnlPercent),
            sprintf('%.2f%%..-moderate-long+%.2f%%|50%%|5', $fromPnlPercent, $fromPnlPercent),
        ];

        return $this->makeDefinition($defs, $priceToRelate, $symbol, $positionSide);
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
