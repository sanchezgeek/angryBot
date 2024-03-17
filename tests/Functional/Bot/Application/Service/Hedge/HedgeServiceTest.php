<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Value\Percent\Percent;
use App\Helper\VolumeHelper;
use App\Tests\Factory\PositionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function sprintf;

final class HedgeServiceTest extends KernelTestCase
{
    private const SUPPORT_PROFIT_MUST_BE_GREATER_THAN_MAIN_POSITION_MARGIN = HedgeService::MAIN_POSITION_IM_PERCENT_FOR_SUPPORT_DEFAULT;

    private readonly HedgeService $hedgeService;

    protected function setUp(): void
    {
        $this->hedgeService = self::getContainer()->get(HedgeService::class);
    }

    /**
     * @dataProvider isSupportSizeEnoughTestCases
     */
    public function testIsSupportSizeEnough(array $positions, bool $expectedResult): void
    {
        $hedge = Hedge::create(...$positions);

        $result = $this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge);

        self::assertEquals($expectedResult, $result);
    }

    public function isSupportSizeEnoughTestCases(): iterable
    {
        $applicablePercentOfIM = new Percent(self::SUPPORT_PROFIT_MUST_BE_GREATER_THAN_MAIN_POSITION_MARGIN, false);
        $notApplicablePercentOfIM = new Percent(self::SUPPORT_PROFIT_MUST_BE_GREATER_THAN_MAIN_POSITION_MARGIN - 1, false);

        ### BTCUSDT SHORT

        $main = PositionFactory::short(Symbol::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(Symbol::BTCUSDT, 34000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, $applicablePercentOfIM)); # div to hedgeDistance
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::short(Symbol::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(Symbol::BTCUSDT, 34000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, $notApplicablePercentOfIM));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        $main = PositionFactory::short(Symbol::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(Symbol::BTCUSDT, 35000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 1000, $notApplicablePercentOfIM));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        ### BTCUSDT LONG

        $main = PositionFactory::long(Symbol::BTCUSDT, 34000, 0.5);
        $support = PositionFactory::short(Symbol::BTCUSDT, 36000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, $applicablePercentOfIM));
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::long(Symbol::BTCUSDT, 34000, 0.5);
        $support = PositionFactory::short(Symbol::BTCUSDT, 36000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, $notApplicablePercentOfIM));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        $main = PositionFactory::long(Symbol::BTCUSDT, 35000, 0.5);
        $support = PositionFactory::short(Symbol::BTCUSDT, 36000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 1000, $notApplicablePercentOfIM));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];
    }

    private static function supportSizeToGetInitialMarginPercentAsProfit(Position $position, float $priceDistance, Percent $percent): float
    {
        return VolumeHelper::round($position->initialMargin->getPercentPart($percent)->value() / $priceDistance);
    }

    private static function testCaseCaption(Position $main, Position $support, bool $expectedResult): string
    {
        return sprintf(
            'main [%s | %.2f | %.4f] vs support [%s | %.2f | %.3f] => %s',
            $main->getCaption(),
            $main->entryPrice,
            $main->size,

            $support->getCaption(),
            $support->entryPrice,
            $support->size,

            $expectedResult ? 'true': 'false',
        );
    }
}