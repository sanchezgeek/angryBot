<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Helper\OutputHelper;
use App\Settings\Application\Helper\SettingsHelper;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\Settings\AutoOpenPositionSettings;

final class AutoOpenNotDisabledCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface
{
    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof AutoOpenNotDisabledCriteria;
    }

    public function checkCriteria(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|FundingIsAppropriateCriteria $criteria
    ): OpenPositionPrerequisiteCheckResult {
        $symbol = $claim->symbol;
        $positionSide = $claim->positionSide;

//        if ($tradingStyle === TradingStyle::Cautious) self::output(sprintf('skip autoOpen (cautious trading style for %s %s)', $symbol->name(), $positionSide->title()));

        if (!SettingsHelper::withAlternatives(AutoOpenPositionSettings::AutoOpen_Enabled, $symbol, $positionSide)) {
            return new OpenPositionPrerequisiteCheckResult(false, OutputHelper::shortClassName(self::class), sprintf('autoOpen disabled for %s %s', $symbol->name(), $positionSide->title()));
        }

        return new OpenPositionPrerequisiteCheckResult(true, OutputHelper::shortClassName(self::class), sprintf('autoOpen not disabled for %s %s', $symbol->name(), $positionSide->title()));
    }
}
