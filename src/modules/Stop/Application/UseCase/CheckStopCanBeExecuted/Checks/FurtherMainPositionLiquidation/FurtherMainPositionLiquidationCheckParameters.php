<?php

declare(strict_types=1);

namespace App\Stop\Application\UseCase\CheckStopCanBeExecuted\Checks\FurtherMainPositionLiquidation;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\ValueObject\Side;
use App\Settings\Application\Service\AppSettingsProviderInterface;
use App\Settings\Application\Service\Dto\SettingValueAccessor;
use App\Stop\Application\Settings\SafePriceDistance;

final readonly class FurtherMainPositionLiquidationCheckParameters implements FurtherMainPositionLiquidationCheckParametersInterface
{
    public function __construct(private AppSettingsProviderInterface $settingsProvider)
    {
    }

    public function mainPositionSafeLiquidationPriceDelta(Symbol $symbol, Side $side, float $refPrice): float
    {
        if ($percentOverride = $this->settingsProvider->get(SettingValueAccessor::bySide(SafePriceDistance::SafePriceDistance_Percent, $symbol, $side), false)) {
            return $refPrice * ($percentOverride / 100);
        }

        return match (true) {
            $refPrice >= 10000 => $refPrice / 12,
            $refPrice >= 5000 => $refPrice / 10,
            $refPrice >= 2000 => $refPrice / 9,
            $refPrice >= 1500 => $refPrice / 8,
            $refPrice >= 1000 => $refPrice / 6,
            $refPrice >= 100 => $refPrice / 4,
            $refPrice >= 1 => $refPrice / 3,
            $refPrice >= 0.1 => $refPrice / 2.5,
            $refPrice >= 0.05 => $refPrice / 2,
            $refPrice >= 0.03 => $refPrice,
            default => $refPrice * 1.4,
            // default => $closingPosition->entryPrice()->deltaWith($ticker->markPrice) * 2
        };
    }
}
