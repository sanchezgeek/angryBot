<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Application\UseCase\Trading\Sandbox\Dto\In\SandboxBuyOrder;
use App\Application\UseCase\Trading\Sandbox\Exception\Unexpected\UnexpectedSandboxExecutionException;
use App\Application\UseCase\Trading\Sandbox\TradingSandboxInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\BuyAndCheckFurtherPositionLiquidation;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FurtherPositionLiquidationAfterBuyIsTooClose;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Helper\OutputHelper;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\Trading\PositionPreset;
use App\Tests\Mixin\Check\ChecksAwareTest;
use App\Tests\Mixin\RateLimiterAwareTest;
use App\Tests\Mixin\Sandbox\SandboxUnitTester;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Trading\Application\Parameters\TradingParametersProviderInterface;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers BuyAndCheckFurtherPositionLiquidation
 *
 * @group checks
 */
final class BuyAndCheckFurtherPositionLiquidationTest extends KernelTestCase
{
    use SettingsAwareTest;
    use SandboxUnitTester;
    use RateLimiterAwareTest;
    use ChecksAwareTest;

    const CHECK_ALIAS = BuyAndCheckFurtherPositionLiquidation::ALIAS;

    private TradingParametersProviderInterface|MockObject $parameters;

    private BuyAndCheckFurtherPositionLiquidation $check;

    protected function setUp(): void
    {
        $this->initializeSandboxTester();
        $this->parameters = $this->createMock(TradingParametersProviderInterface::class);

        $this->check = new BuyAndCheckFurtherPositionLiquidation(
            self::getContainerSettingsProvider(),
            $this->parameters,
            $this->getUnexpectedSandboxExecutionExceptionHandler(),
            $this->createMock(PositionServiceInterface::class),
            $this->tradingSandboxFactory,
            $this->sandboxStateFactory,
        );
    }

    public function testCheckWrapExceptionThrownBySandbox(): void
    {
        $symbol = SymbolEnum::ETHUSDT;
        $side = Side::Buy;
        $ticker = TickerFactory::withEqualPrices($symbol, 1050);
        $position = PositionFactory::long($symbol, 1000);
        $orderDto = self::simpleBuyDto($symbol, $side);
        $thrownException = new RuntimeException('some error');
        $context = TradingCheckContext::withCurrentPositionState($ticker, $position);

        $sandboxOrder = SandboxBuyOrder::fromMarketBuyEntryDto($orderDto, $ticker->lastPrice);
        $sandbox = $this->makeSandboxThatWillThrowException($sandboxOrder, $thrownException);
        $this->mockFactoryToReturnSandbox($symbol, $sandbox);

        // Assert
        $message = sprintf('[%s] Got "%s" error while processing %s order in sandbox (b.id = %d)', OutputHelper::shortClassName(BuyAndCheckFurtherPositionLiquidation::class), $thrownException->getMessage(), SandboxBuyOrder::class, $orderDto->sourceBuyOrder->getId());
        self::expectExceptionObject(new UnexpectedSandboxExecutionException($message, 0, $thrownException));

        // Assert
        $orderDto = new MarketBuyCheckDto($orderDto, $ticker);
        $this->check->check($orderDto, $context);
    }

    /**
     * @dataProvider cases
     */
    public function testBuyOrderCanBeExecuted(
        Ticker $ticker,
        MarketBuyEntryDto $orderDto,
        float $safePriceDistance,
        float $newLiquidation,
        AbstractTradingCheckResult $expectedResult,
    ): void {
        $symbol = $ticker->symbol;
        $positionSide = $orderDto->positionSide;

        $position = PositionBuilder::bySide($positionSide)->symbol($symbol)->build();

        # initial context
        $context = TradingCheckContext::withTicker($ticker);
        $initialSandboxState = self::sampleSandboxState($ticker, $position);
        $context->currentSandboxState = $initialSandboxState;

        # sandbox return state with new position.liquidationPrice
        $newPositionState = PositionClone::clean($position)->withLiquidation($newLiquidation)->create();
        $sandbox = $this->createMock(TradingSandboxInterface::class);
        $newSandboxState = self::sampleSandboxState($ticker, $newPositionState);
        $sandbox->method('getCurrentState')->willReturn($newSandboxState);
        $this->tradingSandboxFactory->method('empty')->with($symbol)->willReturn($sandbox);

        $this->parameters->method('safeLiquidationPriceDelta')->with($symbol, $position->side, $ticker->markPrice->value())->willReturn($safePriceDistance);

        $orderDto = new MarketBuyCheckDto($orderDto, $ticker);
        $result = $this->check->check($orderDto, $context);

        self::assertEquals($expectedResult, $result);
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 65000);
        $safeDistance = 5000;

        ### SHORT
        $side = Side::Sell;
        $order = self::simpleBuyDto($symbol, $side);

        // safe
        $positionAfterSandbox = PositionPreset::safeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $safeDistance, $liq))];

        // also safe (without liquidation)
        $positionAfterSandbox = PositionPreset::withoutLiquidation($side);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $safeDistance, $liq))];

        // not safe
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::liquidationTooCloseResult($ticker, $safeDistance, $liq)];

        // forced
        $order = self::forceBuyDto($symbol, $side);
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success('force flag is set')];

        ### LONG
        $side = Side::Buy;
        $order = self::simpleBuyDto($symbol, $side);

        // safe
        $positionAfterSandbox = PositionPreset::safeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $safeDistance, $liq))];

        // also safe (without liquidation)
        $positionAfterSandbox = PositionPreset::withoutLiquidation($side);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success(self::info($ticker, $safeDistance, $liq))];

        // not safe
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::liquidationTooCloseResult($ticker, $safeDistance, $liq)];

        // forced
        $order = self::forceBuyDto($symbol, $side);
        $positionAfterSandbox = PositionPreset::NOTSafeForMakeBuy($ticker, $side, $safeDistance);
        $liq = $positionAfterSandbox->liquidationPrice;
        yield [$ticker, $order, $safeDistance, $liq, self::success('force flag is set')];
    }

    private static function success(string $info): TradingCheckResult
    {
        return TradingCheckResult::succeed(self::CHECK_ALIAS, $info);
    }

    private static function liquidationTooCloseResult(Ticker $ticker, float $safePriceDistance, float $liquidationPrice): FurtherPositionLiquidationAfterBuyIsTooClose
    {
        $info = self::info($ticker, $safePriceDistance, $liquidationPrice);

        return FurtherPositionLiquidationAfterBuyIsTooClose::create(self::CHECK_ALIAS, $ticker->markPrice, $ticker->symbol->makePrice($liquidationPrice), $safePriceDistance, $info);
    }

    private static function info(Ticker $ticker, float $safePriceDistance, float $liquidationPrice): string
    {
        // @todo | liquidation | null
        if ($liquidationPrice === 0.00) {
            return 'liq=0';
        }

        $liquidationPrice = $ticker->symbol->makePrice($liquidationPrice);

        return sprintf(
            'liq=%s | Δ=%s, safeΔ=%s',
            $liquidationPrice, $liquidationPrice->deltaWith($ticker->markPrice), $ticker->symbol->makePrice($safePriceDistance)
        );
    }

    private static function simpleBuyDto(SymbolInterface $symbol, Side $side): MarketBuyEntryDto
    {
        $buyOrder = new BuyOrder(1, 100500, 0.005, $symbol, $side);

        return MarketBuyEntryDto::fromBuyOrder($buyOrder);
    }

    private static function forceBuyDto(SymbolInterface $symbol, Side $side): MarketBuyEntryDto
    {
        return new MarketBuyEntryDto($symbol, $side, 0.001, true);
    }
}
