<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Handler\Review;

use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use App\Trading\Application\AutoOpen\Decision\OpenPositionConfidenceRateDecisionVoterInterface;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateVotesCollection;
use App\Trading\Application\AutoOpen\Factory\AbstractAutoOpenDecisionCriteriaCollectionFactory;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Dto\PositionAutoOpenParameters;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class AutoOpenClaimReviewer
{
    public function handle(InitialPositionAutoOpenClaim $claim): AutoOpenClaimReviewResult
    {
        $criteriaCollection = $this->getCriteriaCollectionFactory($claim)->create($claim);

        foreach ($criteriaCollection as $criteria) {
            $checker = $this->getCriteriaChecker($claim, $criteria);
            $checkResult = $checker->checkCriteria($claim, $criteria);
            if (!$checkResult->success) {
                return AutoOpenClaimReviewResult::negative([
                    'failedChecks' => [$checkResult->source => $checkResult->info]
                ]);
            }
        }

        $symbol = $claim->symbol;
        $positionSide = $claim->positionSide;

        // @todo | autoOpen | calc size based on further liquidation (must be safe)
        // it can be another criteria =)

        $riskLevel = $this->parameters->riskLevel($symbol, $positionSide);

        [$minPercentOfDepositToUseAsMargin, $maxPercentOfDepositToUseAsMargin] = match ($riskLevel) {
            RiskLevel::Cautious => [0.6, 2],
            default => [0.8, 3],
            RiskLevel::Aggressive => [1.2, 5],
        };

        $confidenceRateVotes = new ConfidenceRateVotesCollection();
        foreach ($criteriaCollection as $criteria) {
            $criteriaVoters = $this->getConfidenceRateVoters($claim, $criteria);
            foreach ($criteriaVoters as $voter) {
                $confidenceRateVotes->add(
                    $voter->makeConfidenceRateVote($claim, $criteria)
                );
            }
        }

### calc part of deposit to use as margin (on 100xLeverage)
        $finalRate = $confidenceRateVotes->getResultRate();

        $percentOfDepositToUseAsMargin = $finalRate->of($maxPercentOfDepositToUseAsMargin);
        $percentOfDepositToUseAsMargin = max($minPercentOfDepositToUseAsMargin, $percentOfDepositToUseAsMargin);

        return new AutoOpenClaimReviewResult(
            new PositionAutoOpenParameters(
                new Percent($percentOfDepositToUseAsMargin)
            ),
            $confidenceRateVotes,
        );
    }

    private function getCriteriaCollectionFactory(InitialPositionAutoOpenClaim $claim): AbstractAutoOpenDecisionCriteriaCollectionFactory
    {
        foreach ($this->criteriaCollectionFactories as $factory) {
            if ($factory->supports($claim)) {
                return $factory;
            }
        }

        throw new RuntimeException(sprintf('Cannot find criteria collection factory for %s claim', $claim));
    }

    private function getCriteriaChecker(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): OpenPositionPrerequisiteCheckerInterface
    {
        foreach ($this->criteriaCheckers as $checker) {
            if ($checker->supportsCriteriaCheck($claim, $criteria)) {
                return $checker;
            }
        }

        throw new RuntimeException(sprintf('Cannot find criteria checker for %s criteria', $criteria));
    }

    /**
     * @param AbstractOpenPositionCriteria $criteria
     * @return OpenPositionConfidenceRateDecisionVoterInterface[]
     */
    private function getConfidenceRateVoters(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): array
    {
        $applicableVotersForCriteria = [];

        foreach ($this->rateDecisionVoters as $voter) {
            if ($voter->supportsMakeConfidenceRateVote($claim, $criteria)) {
                $applicableVotersForCriteria[] = $voter;
            }
        }

        return $applicableVotersForCriteria;
    }

    /**
     * @param iterable<AbstractAutoOpenDecisionCriteriaCollectionFactory> $criteriaCollectionFactories
     * @param iterable<OpenPositionPrerequisiteCheckerInterface> $criteriaCheckers
     * @param iterable<OpenPositionConfidenceRateDecisionVoterInterface> $rateDecisionVoters
     */
    public function __construct(
        #[AutowireIterator('info.info_provider')]
        private iterable $criteriaCollectionFactories,

        #[AutowireIterator('trading.autoOpen.decision.criteria_checker')]
        private iterable $criteriaCheckers,

        #[AutowireIterator('trading.autoOpen.decision.confidence_rate_voter')]
        private iterable $rateDecisionVoters,

        private TradingParametersProviderInterface $parameters,
    ) {
    }
}
