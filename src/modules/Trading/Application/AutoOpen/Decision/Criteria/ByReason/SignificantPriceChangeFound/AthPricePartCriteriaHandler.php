<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Helper\FloatHelper;
use App\Helper\NumberHelper;
use App\Helper\OutputHelper;
use App\TechnicalAnalysis\Application\Helper\TA;
use App\TechnicalAnalysis\Domain\Dto\Ath\PricePartOfAth;
use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;
use App\Trading\Application\AutoOpen\Decision\OpenPositionConfidenceRateDecisionVoterInterface;
use App\Trading\Application\AutoOpen\Decision\OpenPositionPrerequisiteCheckerInterface;
use App\Trading\Application\AutoOpen\Decision\Result\ConfidenceRateDecision;
use App\Trading\Application\AutoOpen\Decision\Result\OpenPositionPrerequisiteCheckResult;
use App\Trading\Application\AutoOpen\Dto\InitialPositionAutoOpenClaim;
use App\Trading\Application\AutoOpen\Reason\AutoOpenOnSignificantPriceChangeReason;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use Exception;
use RuntimeException;

/**
 * @see \App\Tests\Functional\Modules\Trading\Applicaiton\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound\AthPricePartCriteriaHandlerTest
 */
final readonly class AthPricePartCriteriaHandler implements OpenPositionPrerequisiteCheckerInterface, OpenPositionConfidenceRateDecisionVoterInterface
{
    public static function nominalThresholdFromAth(RiskLevel $riskLevel): Percent
    {
        // @todo | autoOpen | funding time + hedge + close
        // @todo | autoOpen | ath | возможно стоит снизить порог, т.к. сейчас будут ещё другие проверки, а эта пропорционально снизит процент депозита

        $percent = match ($riskLevel) {
            RiskLevel::Cautious => 90,
            default => 80,
            RiskLevel::Aggressive => 70,
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

        // @todo возможно проверка isPriceChangeSignificant вообще должна быть здесь

        $threshold = $this->getAthThreshold($claim, $criteria);

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

    public function getAthThreshold(
        InitialPositionAutoOpenClaim $claim,
        AbstractOpenPositionCriteria|AthPricePartCriteria $criteria
    ): Percent {
        $symbol = $claim->symbol;
        $side = $claim->positionSide;

        if (!$side->isShort()) {
            throw new RuntimeException('for shorts');
        }

        /** @var AutoOpenOnSignificantPriceChangeReason $reason */
        $reason = $claim->reason;
        $significantPriceChangeResponse = $reason->significantPriceChangeResponse;
        $info = $significantPriceChangeResponse->info;

        $partOfDayPassed = ($info->toDate->getTimestamp() - $info->fromDate->getTimestamp()) / 86400;

        $baseSignificantPriceChangePercent = $this->parameters->significantPriceChange($symbol, $partOfDayPassed);

        $priceChangePercent = $info->getPriceChangePercent();

        $multiplier = $baseSignificantPriceChangePercent->value() / $priceChangePercent->value();

        if ($partOfDayPassed < 2) {
            $multiplier = $multiplier / ( $partOfDayPassed / 2);
        }

        $multiplier = NumberHelper::minMax($multiplier, 0, 1);

        $threshold = self::nominalThresholdFromAth($this->parameters->riskLevel($symbol, $side));

        /** @var Percent $resultThreshold */
        $resultThreshold = Percent::fromPart($multiplier, false)->of($threshold);

        return new Percent(FloatHelper::round($resultThreshold->value()), false);
    }

    public function makeConfidenceRateVote(InitialPositionAutoOpenClaim $claim, AbstractOpenPositionCriteria $criteria): ConfidenceRateDecision
    {
        $symbol = $claim->symbol;
        $positionSide = $claim->positionSide;

        $ticker = $this->exchangeService->ticker($symbol);
        $price = $ticker->markPrice;

        $currentPricePartOfAth = TA::pricePartOfAthExtended($symbol, $price);

        $currentPricePartOfAth = self::prepareAthResultPercent($currentPricePartOfAth, $positionSide);
        $highLowPricesSource = $currentPricePartOfAth->source;

        if ($currentPricePartOfAth->isPriceMovedOverLow()) {
            if ($positionSide->isShort()) {
                $rate = $price->value() / $highLowPricesSource->low->value() / 10;
            } else {
                try {
                    $rate = Percent::fromPart(($price->value() - $highLowPricesSource->high->value()) / $highLowPricesSource->delta(), false)->getComplement()->part() / 10;
                } catch (Exception) {
                    $rate = 0.01;
                }

                $rate = max(0.01, $rate);
            }
        } else {
            $rate = $currentPricePartOfAth->percent->part();

            $min = 0.1;
            if ($rate < $min) {
                $rate = $min;
            }
        }

        return new ConfidenceRateDecision(
            OutputHelper::shortClassName($this),
            Percent::fromPart(FloatHelper::round($rate), false),
            'current price part of ATH'
        );
    }

    private static function prepareAthResultPercent(PricePartOfAth $partOfAth, Side $positionSide): PricePartOfAth
    {
        $initialPart = $partOfAth->percent->part();

        if ($positionSide->isLong()) {
            if ($partOfAth->isPriceMovedOverLow()) {
                return PricePartOfAth::overHigh($partOfAth->source, 1 + $initialPart);
            } elseif ($partOfAth->isPriceMovedOverHigh()) {
                return PricePartOfAth::overLow($partOfAth->source, 1 - $initialPart);
            }

            return $partOfAth->invert();
        }

        return $partOfAth;
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
