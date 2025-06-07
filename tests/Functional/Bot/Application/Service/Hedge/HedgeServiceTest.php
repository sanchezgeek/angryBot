<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Application\Service\Hedge;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Value\Percent\Percent;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function sprintf;

final class HedgeServiceTest extends KernelTestCase
{
    use ByBitV5ApiRequestsMocker;

    private const M_POSITION_IM_PERCENT_TO_SUPPORT_DEFAULT = HedgeService::M_POSITION_IM_PERCENT_TO_SUPPORT_DEFAULT;
    private const M_POSITION_IM_PERCENT_TO_SUPPORT_RANGES = HedgeService::M_POSITION_IM_PERCENT_TO_SUPPORT_RANGES;
    private const M_POSITION_IM_PERCENT_TO_SUPPORT_MIN = HedgeService::M_POSITION_IM_PERCENT_TO_SUPPORT_MIN;

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
        $mainPosition = $hedge->mainPosition;

        $this->haveTicker(TickerFactory::create($mainPosition->symbol, $mainPosition->entryPrice)); # for simplify assume ticker is on mainPosition.entry
        $this->haveAvailableSpotBalance($mainPosition->symbol, 0);
        $this->haveContractWalletBalanceAllUsedToOpenPosition($mainPosition);

        $result = $this->hedgeService->isSupportSizeEnoughForSupportMainPosition($hedge);

        self::assertEquals($expectedResult, $result);
    }

    public function isSupportSizeEnoughTestCases(): iterable
    {
        ### BTCUSDT SHORT

        $main = PositionFactory::short(SymbolEnum::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(SymbolEnum::BTCUSDT, 34000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, true));
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::short(SymbolEnum::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(SymbolEnum::BTCUSDT, 34000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, false));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        $main = PositionFactory::short(SymbolEnum::BTCUSDT, 36000, 0.5);
        $support = PositionFactory::long(SymbolEnum::BTCUSDT, 35000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 1000, false));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        ### BTCUSDT LONG

        $main = PositionFactory::long(SymbolEnum::BTCUSDT, 34000, 0.5);
        $support = PositionFactory::short(SymbolEnum::BTCUSDT, 36000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, true));
        yield self::testCaseCaption($main, $support, true) => [[$main, $support], 'expectedResult' => true];

        $main = PositionFactory::long(SymbolEnum::BTCUSDT, 34000, 0.5);
        $support = PositionFactory::short(SymbolEnum::BTCUSDT, 36000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 2000, false));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];

        $main = PositionFactory::long(SymbolEnum::BTCUSDT, 35000, 0.5);
        $support = PositionFactory::short(SymbolEnum::BTCUSDT, 36000, self::supportSizeToGetInitialMarginPercentAsProfit($main, 1000, false));
        yield self::testCaseCaption($main, $support, false) => [[$main, $support], 'expectedResult' => false];
    }

    private static function supportSizeToGetInitialMarginPercentAsProfit(Position $mainPosition, float $priceDistance, bool $isSizeMustBeApplicableForSupport): float
    {
        $mainImPercentToSupport = self::getExpectedMainPositionIMPercentToSupport($mainPosition);
        if (!$isSizeMustBeApplicableForSupport) {
            $mainImPercentToSupport = $mainImPercentToSupport->sub(1);
        }

        return $mainPosition->symbol->roundVolume($mainPosition->initialMargin->getPercentPart($mainImPercentToSupport)->value() / $priceDistance);
    }

    /**
     * @see HedgeService::getDefaultMainPositionIMPercentToSupport
     */
    private static function getExpectedMainPositionIMPercentToSupport(Position $mainPosition): Percent
    {
        $ticker = TickerFactory::create($mainPosition->symbol, $mainPosition->entryPrice);
        $currentMainPositionPnlPercent = $ticker->indexPrice->getPnlPercentFor($mainPosition);

        $mainImPercentToSupport = self::M_POSITION_IM_PERCENT_TO_SUPPORT_DEFAULT;
        foreach (self::M_POSITION_IM_PERCENT_TO_SUPPORT_RANGES as $key => [$fromPnl, $toPnl, $expectedMainImPercentToSupport]) {
            if ($key === 0 && $currentMainPositionPnlPercent < $fromPnl) {
                $mainImPercentToSupport = self::M_POSITION_IM_PERCENT_TO_SUPPORT_MIN;
                break;
            }
            if ($currentMainPositionPnlPercent >= $fromPnl && $currentMainPositionPnlPercent <= $toPnl) {
                $mainImPercentToSupport = $expectedMainImPercentToSupport;
                break;
            }
        }

        return new Percent($mainImPercentToSupport, false);
    }

    private static function testCaseCaption(Position $main, Position $support, bool $expectedResult): string
    {
        return sprintf(
            'main [%s | %.2f | %.4f] vs support [%s | %.2f | %.3f] => %s',
            $main->getCaption(), $main->entryPrice, $main->size, $support->getCaption(), $support->entryPrice, $support->size, $expectedResult ? 'true': 'false'
        );
    }
}
