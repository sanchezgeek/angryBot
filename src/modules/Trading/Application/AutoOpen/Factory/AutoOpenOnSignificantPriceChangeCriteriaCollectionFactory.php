<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Factory;

use App\Notification\Application\Contract\AppNotificationsServiceInterface;
use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\AthPricePartCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\AutoOpenNotDisabledCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\FundingIsAppropriateCriteria;
use App\Trading\Application\AutoOpen\Decision\Criteria\InstrumentAgeIsAppropriateCriteria;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Reason\AutoOpenOnSignificantPriceChangeReason;
use RuntimeException;

final class AutoOpenOnSignificantPriceChangeCriteriaCollectionFactory extends AbstractAutoOpenDecisionCriteriaCollectionFactory
{
    public function supports(InitialPositionAutoOpenClaim $claim): bool
    {
        return $claim->reason instanceof AutoOpenOnSignificantPriceChangeReason;
    }

    public function create(InitialPositionAutoOpenClaim|AutoOpenOnSignificantPriceChangeReason $claim): array
    {
        if (!$this->supports($claim)) {
            throw new RuntimeException('unsupported reason');
        }

        $defaultCriterias = [
            new AutoOpenNotDisabledCriteria(),
            new InstrumentAgeIsAppropriateCriteria(),
            new FundingIsAppropriateCriteria(),
            new AthPricePartCriteria(),
        ];

        $criterias = self::mapToAliases($defaultCriterias);

        foreach ($claim->reason->source->source->criteriasSuggestions as $criteriasSuggestion) {
            $this->notifications->muted(sprintf('Override %s for %s %s with %s', $criteriasSuggestion::getAlias(), $claim->symbol->name(), $claim->positionSide->value, $criteriasSuggestion));
            $criterias[$criteriasSuggestion::getAlias()] = $criteriasSuggestion;
        }

        return $criterias;
    }

    private static function mapToAliases(array $criterias): array
    {
        return array_combine(
            array_map(static fn (AbstractOpenPositionCriteria $criterion) => $criterion::getAlias(), $criterias),
            $criterias
        );
    }

    public function __construct(
        private readonly AppNotificationsServiceInterface $notifications
    ) {
    }
}
