<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Factory;

use App\Bot\Domain\Position;
use App\Domain\Position\Helper\InitialMarginHelper;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Helper\DateTimeHelper;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\LinpByPeriodicalFixationsStrategyDto;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step\PeriodicalFixationStep;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\LinpByStopStepsStrategyDto;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\Step\LinpByStopsGridStep;
use App\Trading\Contract\ContractBalanceProviderInterface;

final readonly class LockInProfitStrategiesDtoFactory
{
    public function threeStopStepsLock(Position $position, ?RiskLevel $riskLevel = null): LinpByStopStepsStrategyDto
    {
        // @todo | lockInProfit | stops | based on ath part
        // @todo | lockInProfit | stops | based on funding
        // @todo | lockInProfit | stops | based on instrument age
        // @todo | add trading style to alias (for further recreate after style changed)
        // @todo | lockInProfit | ability to specify price from which make grid
        return new LinpByStopStepsStrategyDto(
            // first part
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part1',
                Length::Standard, // settings?
                self::makeGridDefinition(Length::VeryVeryShort->toStringWithNegativeSign(), Length::Standard->toStringWithNegativeSign(), 20, 10),
            ),
            // second part
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part2',
                Length::BetweenLongAndStd,
                self::makeGridDefinition(Length::VeryVeryShort->toStringWithNegativeSign(), Length::Long->toStringWithNegativeSign(), 15, 10),
            ),
            // third part: back to position entry
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part3',
                Length::Long,
                // @todo | lockInProfit | stops must be placed near position side
                self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::VeryLong->toStringWithNegativeSign(), 30, 30),
            ),
            // fourth part: back to position entry
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part4',
                Length::VeryVeryLong,
                self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::VeryLong->toStringWithNegativeSign(), 25, 10),
            ),
        );
    }

    public function defaultPeriodicalFixationsLock(Position $position): LinpByPeriodicalFixationsStrategyDto
    {
        $smallPartToClose = $this->minPercentToClose($position);
        $bigPartToClose = new Percent($smallPartToClose->value() * 2);

        // @todo use funding here
        // @todo use free to total balance ratio (make faster for negative free)

        return new LinpByPeriodicalFixationsStrategyDto(
            new PeriodicalFixationStep(
                'periodical-fixation-on-long-distance',
                PriceDistanceSelector::Long,
                $smallPartToClose,
                new Percent(10),
                DateTimeHelper::secondsInMinutes(30) // settings based on TradingStyle
            ),
            new PeriodicalFixationStep(
                'periodical-fixation-on-very-long-distance',
                PriceDistanceSelector::VeryLong,
                $bigPartToClose,
                new Percent(15),
                DateTimeHelper::secondsInMinutes(20)
            ),
            new PeriodicalFixationStep(
                'periodical-fixation-on-very-very-long-distance',
                PriceDistanceSelector::VeryVeryLong,
                $smallPartToClose,
                new Percent(20),
                DateTimeHelper::secondsInMinutes(30)
            ),
            new PeriodicalFixationStep(
                'periodical-fixation-on-double-long-distance',
                PriceDistanceSelector::DoubleLong,
                $bigPartToClose,
                new Percent(20),
                DateTimeHelper::secondsInMinutes(30)
            ),
        );
    }

    public function minPercentToClose(Position $position): Percent
    {
        $im = InitialMarginHelper::realInitialMargin($position);
        $contractBalance = $this->contractBalanceProvider->getContractWalletBalance($position->symbol->associatedCoin());

        $totalWithUnrealized = $contractBalance->totalWithUnrealized()->value();
        $imRatio = $im / $totalWithUnrealized / 2;

        $minPercentToClose = .005;
        $maxPercentToClose = .015;

        $part = $imRatio * 100 / $totalWithUnrealized;

        if ($part > $maxPercentToClose) {
            $part = $maxPercentToClose;
        }

        if ($part < $minPercentToClose) {
            $part = $minPercentToClose;
        }

        return Percent::fromPart($part, false);
    }

    public static function makeGridDefinition(
        string $from,
        string $to,
        float $positionSizePercent,
        int $stopsCount,
    ): string {
        return sprintf('%s..%s|%d%%|%s', $from, $to, $positionSizePercent, $stopsCount);
    }

    public function __construct(
        private ContractBalanceProviderInterface $contractBalanceProvider,
    ) {
    }
}
