<?php

declare(strict_types=1);

namespace App\Buy\Domain\Enum;

enum StopPriceDefinitionType: string
{
    case BasedOn_RiskToRewardRatio = 'rr';
    case BasedOn_PredefinedStopLength = 'without-tp';
}
