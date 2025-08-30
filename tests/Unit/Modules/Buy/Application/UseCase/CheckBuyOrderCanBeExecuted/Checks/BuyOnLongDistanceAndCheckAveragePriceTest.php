<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Buy\Application\Helper\BuyOrderInfoHelper;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyOnLongDistanceAndCheckAveragePrice;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyOrderPlacedTooFarFromPositionEntry;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Domain\Trading\Enum\PriceDistanceSelector;
use App\Domain\Value\Percent\Percent;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Helper\Trading\PositionPreset;
use App\Tests\Helper\Trading\TickerPreset;
use App\Tests\Mixin\Check\ChecksAwareTest;
use App\Tests\Mixin\RateLimiterAwareTest;
use App\Tests\Mixin\Sandbox\SandboxUnitTester;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\Trading\TradingParametersMocker;
use App\Tests\Stub\TA\TradingParametersProviderStub;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers BuyOnLongDistanceAndCheckAveragePrice
 *
 * @group checks
 */
final class BuyOnLongDistanceAndCheckAveragePriceTest extends KernelTestCase
{
    use SettingsAwareTest;
    use SandboxUnitTester;
    use RateLimiterAwareTest;
    use ChecksAwareTest;
    use TradingParametersMocker;

    private const float MAX_ALLOWED_PERCENT_PRICE_CHANGE_BETWEEN_POSITION_AND_TICKER = 2;

    private const PriceDistanceSelector DEFAULT_MAX_ALLOWED_PRICE_DISTANCE = BuyOnLongDistanceAndCheckAveragePrice::DEFAULT_MAX_ALLOWED_PRICE_DISTANCE;

    const string CHECK_ALIAS = BuyOnLongDistanceAndCheckAveragePrice::ALIAS;

    /**
     * @dataProvider cases
     */
    public function testBuyOrderCanBeExecuted(
        Position $position,
        Ticker $ticker,
        MarketBuyEntryDto $orderDto,
        float $maxAllowedPercentPriceChange,
        AbstractTradingCheckResult $expectedResult,
    ): void {
        $symbol = $ticker->symbol;

        self::setTradingParametersStubInContainer(self::getTradingParametersStub($symbol, $maxAllowedPercentPriceChange));

        $context = TradingCheckContext::withCurrentPositionState($ticker, $position);
        $orderDto = new MarketBuyCheckDto($orderDto, $ticker);

        // Act
        $result = $this->getCheckService()->check($orderDto, $context);

        // Assert
        self::assertEquals($expectedResult, $result);
    }

    private static function getTradingParametersStub(SymbolInterface $symbol, float $percentChange): TradingParametersProviderStub
    {
        $timeframe = TradingParametersProviderInterface::LONG_ATR_TIMEFRAME;
        $period = TradingParametersProviderInterface::ATR_PERIOD_FOR_ORDERS;

        return
            new TradingParametersProviderStub()
                ->addOppositeBuyLengthResult($symbol, self::DEFAULT_MAX_ALLOWED_PRICE_DISTANCE, $timeframe, $period, new Percent($percentChange))
            ;
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $maxAllowedPercentPriceChange = self::MAX_ALLOWED_PERCENT_PRICE_CHANGE_BETWEEN_POSITION_AND_TICKER;

        ### SHORT
        $side = Side::Sell;
        $position = PositionBuilder::bySide($side)->entry(100000)->build();

        // allowed
        $ticker = TickerPreset::notSoLongFromPositionEntry($position, $maxAllowedPercentPriceChange);
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::success(self::info($ticker, $position, $order, $maxAllowedPercentPriceChange));
        yield $result->info() => [$position, $ticker, $order, $maxAllowedPercentPriceChange, $result];

        // not allowed
        $ticker = TickerPreset::tooFarFromPositionEntry($position, $maxAllowedPercentPriceChange);
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::buyOrderPlacedTooFarFromPositionEntryResult($ticker, $position, $order, $maxAllowedPercentPriceChange);
        yield $result->info() => [$position, $ticker, $order, $maxAllowedPercentPriceChange, $result];

        // forced
        $ticker = TickerPreset::tooFarFromPositionEntry($position, $maxAllowedPercentPriceChange);
        $order = self::forceBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::successBecauseDisabled(self::info($ticker, $position, $order, $maxAllowedPercentPriceChange));
        yield $result->info() => [$position, $ticker, $order, $maxAllowedPercentPriceChange, $result];

        ### LONG
        $side = Side::Buy;
        $position = PositionBuilder::bySide($side)->entry(50000)->build();

        // allowed
        $ticker = TickerPreset::notSoLongFromPositionEntry($position, $maxAllowedPercentPriceChange);
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::success(self::info($ticker, $position, $order, $maxAllowedPercentPriceChange));
        yield $result->info() => [$position, $ticker, $order, $maxAllowedPercentPriceChange, $result];

        // not allowed
        $ticker = TickerPreset::tooFarFromPositionEntry($position, $maxAllowedPercentPriceChange);
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::buyOrderPlacedTooFarFromPositionEntryResult($ticker, $position, $order, $maxAllowedPercentPriceChange);
        yield $result->info() => [$position, $ticker, $order, $maxAllowedPercentPriceChange, $result];

        // forced
        $ticker = TickerPreset::tooFarFromPositionEntry($position, $maxAllowedPercentPriceChange);
        $order = self::forceBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::successBecauseDisabled(self::info($ticker, $position, $order, $maxAllowedPercentPriceChange));
        yield $result->info() => [$position, $ticker, $order, $maxAllowedPercentPriceChange, $result];
    }

    private static function success(string $info): TradingCheckResult
    {
        return TradingCheckResult::succeed(self::CHECK_ALIAS, $info);
    }

    private static function successBecauseDisabled(string $info): TradingCheckResult
    {
        return TradingCheckResult::succeed(self::CHECK_ALIAS, sprintf('[disabled] %s', $info));
    }

    private static function buyOrderPlacedTooFarFromPositionEntryResult(Ticker $ticker, Position $position, MarketBuyEntryDto $orderDto, float $maxAllowedPercentChange): BuyOrderPlacedTooFarFromPositionEntry
    {
        $info = self::info($ticker, $position, $orderDto, $maxAllowedPercentChange);
        $orderPrice = $ticker->markPrice;
        $percentChange = $orderPrice->differenceWith($position->entryPrice())->getPercentChange($position->side)->abs();

        return BuyOrderPlacedTooFarFromPositionEntry::create(self::CHECK_ALIAS, $position->entryPrice(), $orderPrice, Percent::notStrict($maxAllowedPercentChange), $percentChange, $info);
    }

    private static function info(Ticker $ticker, Position $position, MarketBuyEntryDto $order, float $maxAllowedPercentChange): string
    {
        $orderPrice = $ticker->markPrice;
        $positionEntryPrice = $position->entryPrice();
        $percentChange = $orderPrice->differenceWith($positionEntryPrice)->getPercentChange($position->side)->abs();

        return sprintf(
            'markPrice = %s, entry=%s | %%Δ=%s, allowed%%Δ=%s',
            $orderPrice,
            $positionEntryPrice,
            $percentChange,
            Percent::notStrict($maxAllowedPercentChange),
        );
    }

    private static function simpleBuyDto(SymbolInterface $symbol, Side $side, SymbolPrice $price): MarketBuyEntryDto
    {
        $buyOrder = new BuyOrder(1, $price, 0.005, $symbol, $side);

        return MarketBuyEntryDto::fromBuyOrder($buyOrder);
    }

    private static function forceBuyDto(SymbolInterface $symbol, Side $side, SymbolPrice $price): MarketBuyEntryDto
    {
        $buyOrder = new BuyOrder(1, $price, 0.005, $symbol, $side)->disableAveragePriceCheck();

        return new MarketBuyEntryDto($symbol, $side, 0.001, true, $buyOrder);
    }

    private function getCheckService(): BuyOnLongDistanceAndCheckAveragePrice
    {
        return self::getContainer()->get(BuyOnLongDistanceAndCheckAveragePrice::class);
    }
}
