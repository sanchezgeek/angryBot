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
                self::makeGridDefinition(Length::VeryVeryShort->toStringWithNegativeSign(), Length::BetweenLongAndStd->toStringWithNegativeSign(), 20, 10),
            ),
            // third part: back to position entry
            new LinpByStopsGridStep(
                'defaultThreeStepsLock-part3',
                Length::Long,
                // @todo | lockInProfit | stops must be placed near position side
                self::makeGridDefinition(Length::Short->toStringWithNegativeSign(), Length::Long->toStringWithNegativeSign(), 40, 30),
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
        return new LinpByPeriodicalFixationsStrategyDto(
            new PeriodicalFixationStep(
                'periodical-fixation-on-standard-distance',
                PriceDistanceSelector::Long,
                new Percent(0.5),
                new Percent(10),
                DateTimeHelper::secondsInMinutes(30) // settings based on TradingStyle
            )
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
