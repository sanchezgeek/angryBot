<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Value\Percent\Percent;
use App\Tests\Factory\PositionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function sprintf;

final class HedgeServiceTest extends KernelTestCase
{
    private const SUPPORT_PROFIT_MUST_BE_GREATER_THAN_MAIN_POSITION_MARGIN = HedgeService::SUPPORT_PROFIT_MUST_BE_GREATER_THAN_MAIN_POSITION_MARGIN;

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
        $applicablePercentOfIM = new Percent(self::SUPPORT_PROFIT_MUST_BE_GREATER_THAN_MAIN_POSITION_MARGIN);
        $notApplicablePercentOfIM = new Percent(self::SUPPORT_PROFIT_MUST_BE_GREATER_THAN_MAIN_POSITION_MARGIN - 0.5);

        ### BTCUSDT SHORT

        $main = PositionFactory::short(Symbol::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(Symbol::BTCUSDT, 35000, $main->initialMargin->getPercentPart($applicablePercentOfIM)->value() / 1000); # div to hedgeDistance
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::short(Symbol::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(Symbol::BTCUSDT, 35000, $main->initialMargin->getPercentPart($notApplicablePercentOfIM)->value() / 1000);
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        $main = PositionFactory::short(Symbol::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(Symbol::BTCUSDT, 35500, $main->initialMargin->getPercentPart($applicablePercentOfIM)->value() / 500);
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::short(Symbol::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(Symbol::BTCUSDT, 35500, $main->initialMargin->getPercentPart($notApplicablePercentOfIM)->value() / 500);
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        ### BTCUSDT LONG

        $main = PositionFactory::long(Symbol::BTCUSDT, 35000, 0.5);
        $support = PositionFactory::short(Symbol::BTCUSDT, 36000, $main->initialMargin->getPercentPart($applicablePercentOfIM)->value() / 1000);
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::long(Symbol::BTCUSDT, 35000, 0.5);
        $support = PositionFactory::short(Symbol::BTCUSDT, 36000, $main->initialMargin->getPercentPart($notApplicablePercentOfIM)->value() / 1000);
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        $main = PositionFactory::long(Symbol::BTCUSDT, 35500, 0.5);
        $support = PositionFactory::short(Symbol::BTCUSDT, 36000, $main->initialMargin->getPercentPart($applicablePercentOfIM)->value() / 500);
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::long(Symbol::BTCUSDT, 35500, 0.5);
        $support = PositionFactory::short(Symbol::BTCUSDT, 36000, $main->initialMargin->getPercentPart($notApplicablePercentOfIM)->value() / 500);
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];
    }

    private static function testCaseCaption(Position $main, Position $support, bool $expectedResult): string
    {
        return sprintf(
            'main [%s | %.2f | %.4f] vs support [%s | %.2f | %.4f] => %s',
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