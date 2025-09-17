<?php

declare(strict_types=1);

namespace App\Trading\Application\LockInProfit\Factory;

use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Trading\Enum\PriceDistanceSelector as Length;
use App\Domain\Trading\Enum\RiskLevel;
use App\Domain\Value\Percent\Percent;
use App\Helper\DateTimeHelper;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\LinpByPeriodicalFixationsStrategyDto;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByPeriodicalFixations\Step\PeriodicalFixationStep;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\LinpByStopStepsStrategyDto;
use App\Trading\Application\LockInProfit\Strategy\LockInProfitByStopSteps\Step\LinpByStopsGridStep;

final class LockInProfitStrategiesDtoFactory
{
    public function threeStopStepsLock(?RiskLevel $riskLevel = null): LinpByStopStepsStrategyDto
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
                Length::BetweenShortAndStd, // settings?
                self::makeGridDefinition(Length::VeryVeryShort->toStringWithNegativeSign(), Length::Standard->toStringWithNegativeSign(), 20, 10),
            ),
            // second part
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part2',
                Length::Standard,
                self::makeGridDefinition(Length::VeryVeryShort->toStringWithNegativeSign(), Length::BetweenLongAndStd->toStringWithNegativeSign(), 15, 10),
            ),
            // third part: back to position entry
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part3',
                Length::Long,
                // @todo | lockInProfit | stops must be placed near position side
                self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::Long->toStringWithNegativeSign(), 30, 30),
            ),
            // fourth part: back to position entry
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part4',
                Length::VeryVeryLong,
                self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::VeryLong->toStringWithNegativeSign(), 40, 10),
            ),
        );
    }

    public function defaultPeriodicalFixationsLock(): LinpByPeriodicalFixationsStrategyDto
    {
        // @todo use funding here

        return new LinpByPeriodicalFixationsStrategyDto(
            new PeriodicalFixationStep(
                'periodical-fixation-on-long-distance',
                PriceDistanceSelector::Long,
                new Percent(0.5),
                new Percent(12),
                DateTimeHelper::secondsInMinutes(30) // settings based on TradingStyle
            ),
            new PeriodicalFixationStep(
                'periodical-fixation-on-very-long-distance',
                PriceDistanceSelector::VeryLong,
                new Percent(0.5),
                new Percent(15),
                DateTimeHelper::secondsInMinutes(30)
            ),
            new PeriodicalFixationStep(
                'periodical-fixation-on-very-very-long-distance',
                PriceDistanceSelector::VeryVeryLong,
                new Percent(1),
                new Percent(20),
                DateTimeHelper::secondsInMinutes(5)
            ),
            new PeriodicalFixationStep(
                'periodical-fixation-on-double-long-distance',
                PriceDistanceSelector::DoubleLong,
                new Percent(1),
                new Percent(20),
                DateTimeHelper::secondsInMinutes(5)
            ),
        );
    }

    public static function makeGridDefinition(
        string $from,
        string $to,
        float $positionSizePercent,
        int $stopsCount,
    ): string {
        return sprintf('%s..%s|%d%%|%s', $from, $to, $positionSizePercent, $stopsCount);
    }
}
