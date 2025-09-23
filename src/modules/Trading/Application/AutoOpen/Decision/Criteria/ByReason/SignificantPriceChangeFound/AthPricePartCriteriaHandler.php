<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use App\Trading\Application\AutoOpen\Decision\OpenPositionConfidenceRateDecisionVoterInterface;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Reason\AutoOpenOnSignificantPriceChangeReason;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;

/**
 * @see \App\Tests\Functional\Modules\Trading\Applicaiton\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound\AthPricePartCriteriaHandlerTest
 */
final readonly class AthPricePartCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface, OpenPositionConfidenceRateDecisionVoterInterface
{
    public static function usedThresholdFromAth(RiskLevel $riskLevel): Percent
    {
        // @todo | autoOpen | funding time + hedge + close
        // @todo | autoOpen | ath | возможно стоит снизить порог, т.к. сейчас будут ещё другие проверки, а эта пропорционально снизит процент депозита

        $percent = match ($riskLevel) {
            RiskLevel::Cautious => 85,
            default => 75,
            RiskLevel::Aggressive => 60,
        };

        return new Percent($percent);
    }

    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof AthPricePartCriteria && $claim->reason instanceof AutoOpenOnSignificantPriceChangeReason;
    }

    public function supportsMakeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof AthPricePartCriteria && $claim->reason instanceof AutoOpenOnSignificantPriceChangeReason;
    }

    public function checkCriteria(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|AthPricePartCriteria $criteria
    ): OpenPositionPrerequisiteCheckResult {
        $symbol = $claim->symbol;
        $side = $claim->positionSide;

        // для лонгов можно сделать если меньше ATL либо второго минимума по структуре

        // @todo | autoOpen | skip (now only for SHORTs) // diable force opposite for buy through context
        if (!$side->isShort()) {
            return new OpenPositionPrerequisiteCheckResult(false, OutputHelper::shortClassName(self::class), 'autoOpen disabled for LONGs', true);
        }

        $threshold = self::usedThresholdFromAth($this->parameters->riskLevel($symbol, $side));

        if ($modifier = $criteria->getAthThresholdModifier($side)) {
            $threshold = $modifier->of($threshold);
            // longs logic
        }

        $currentPricePartOfAth = $this->getCurrentPricePartOfAth($symbol);
        if ($currentPricePartOfAth->value() < $threshold->value()) {
            $thresholdForNotification = $threshold->value();
//            $thresholdForNotification -= ($thresholdForNotification / 10);
            $thresholdForNotification -= ($thresholdForNotification / 2);

            return new OpenPositionPrerequisiteCheckResult(
                false,
                OutputHelper::shortClassName(self::class),
                sprintf('$currentPricePartOfAth (%s) < %s%%', $currentPricePartOfAth, $threshold),
                silent: $currentPricePartOfAth->value() < $thresholdForNotification // notify in some range
            );
        }

        return new OpenPositionPrerequisiteCheckResult(
            true,
            OutputHelper::shortClassName(self::class),
            sprintf('$currentPricePartOfAth (%s) >= %s%%)', $currentPricePartOfAth, $threshold)
        );
    }

    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRateDecision
    {
        $symbol = $claim->symbol;
        $currentPricePartOfAth = $this->getCurrentPricePartOfAth($symbol);

        return new ConfidenceRateDecision(
            OutputHelper::shortClassName($this),
            $currentPricePartOfAth,
            'current price part of ATH'
        );
    }


    private function getCurrentPricePartOfAth(SymbolInterface $symbol): Percent
    {
        $ticker = $this->exchangeService->ticker($symbol);

        return TA::pricePartOfAth($symbol, $ticker->markPrice);
    }

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private TradingParametersProviderInterface $parameters,
    ) {
    }
}
