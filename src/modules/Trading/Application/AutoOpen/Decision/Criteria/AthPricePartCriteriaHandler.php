<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\Trading\Application\AutoOpen\Decision\OpenPositionConfidenceRateDecisionVoterInterface;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use LogicException;

final readonly class AthPricePartCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface, OpenPositionConfidenceRateDecisionVoterInterface
{
    public static function usedThresholdFromAth(RiskLevel $riskLevel): float
    {
        // @todo | autoOpen | funding time + hedge + close

        return match ($riskLevel) {
            RiskLevel::Cautious => 85,
            default => 70,
            RiskLevel::Aggressive => 65,
        };
    }

    public function supportsCriteriaCheck(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof AthPricePartCriteria;
    }

    public function supportsMakeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): bool
    {
        return $criteria instanceof AthPricePartCriteria;
    }

    public function checkCriteria(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|AthPricePartCriteria $criteria
    ): OpenPositionPrerequisiteCheckResult {
        $symbol = $claim->symbol;
        $positionSide = $claim->positionSide;

        if (!$positionSide->isShort()) {
            throw new LogicException('Now only for SHORTs');
        }

        $currentPricePartOfAth = $this->getCurrentPricePartOfAth($symbol);

        $riskLevel = $this->parameters->riskLevel($symbol, $positionSide);
        $threshold = self::usedThresholdFromAth($riskLevel);

        if ($currentPricePartOfAth->value() < $threshold) {
            $thresholdForNotification = $threshold;
            $thresholdForNotification -= ($thresholdForNotification / 10);

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

        return TA::currentPricePartOfAth($symbol, $ticker->markPrice);
    }

    public function __construct(
        private ExchangeServiceInterface $exchangeService,
        private TradingParametersProviderInterface $parameters,
    ) {
    }
}
