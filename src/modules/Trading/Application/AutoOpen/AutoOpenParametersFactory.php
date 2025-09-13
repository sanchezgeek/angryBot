<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\RiskLevel;
use App\Info\Contract\DependencyInfoProviderInterface;
use App\Info\Contract\Dto\AbstractDependencyInfo;
use App\Info\Contract\Dto\InfoAboutEnumDependency;
use App\Settings\Application\Contract\AppDynamicParametersProviderInterface;
use App\Settings\Application\DynamicParameters\Attribute\AppDynamicParameter;
use App\Trading\Application\Parameters\TradingDynamicParameters;
use App\Trading\Domain\Symbol\SymbolInterface;

final readonly class AutoOpenParametersFactory implements DependencyInfoProviderInterface, AppDynamicParametersProviderInterface
{
    public function __construct()
    {
    }

    #[AppDynamicParameter(group: 'autoOpen', name: 'params')]
    public function create(
        SymbolInterface $symbol,
        Side $positionSide,
        ?RiskLevel $riskLevel = null
    ): AutoOpenParameters {
        $riskLevel = $riskLevel ?? TradingDynamicParameters::riskLevel($symbol, $positionSide);

        [$minPercentOfDepositToUseAsMargin, $maxPercentOfDepositToUseAsMargin] = match ($riskLevel) {
            RiskLevel::Cautious => [0.8, 4],
            default => [1, 6],
            RiskLevel::Aggressive => [1.5, 8],
        };

        return new AutoOpenParameters(
            $riskLevel,
            $minPercentOfDepositToUseAsMargin,
            $maxPercentOfDepositToUseAsMargin
        );
    }

    public function getDependencyInfo(): AbstractDependencyInfo
    {
        return InfoAboutEnumDependency::create(AutoOpenParameters::class, RiskLevel::class, '');
    }
}
