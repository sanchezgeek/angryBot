<?php

declare(strict_types=1);

namespace App\Tests\Unit\Modules\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks;

use App\Application\UseCase\Trading\MarketBuy\Dto\MarketBuyEntryDto;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Buy\Application\Helper\BuyOrderInfoHelper;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Checks\DenyBuyIfFixationsExists;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\MarketBuyCheckDto;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\BuyCheckFailureEnum;
use App\Buy\Application\UseCase\CheckBuyOrderCanBeExecuted\Result\FixationsFound;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\Position\PositionBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\Check\ChecksAwareTest;
use App\Tests\Mixin\RateLimiterAwareTest;
use App\Tests\Mixin\Sandbox\SandboxUnitTester;
use App\Tests\Mixin\Settings\SettingsAwareTest;
use App\Tests\Mixin\StopsTester;
use App\Trading\Domain\Symbol\SymbolInterface;
use App\Trading\SDK\Check\Contract\Dto\Out\AbstractTradingCheckResult;
use App\Trading\SDK\Check\Dto\TradingCheckContext;
use App\Trading\SDK\Check\Dto\TradingCheckResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers DenyBuyIfFixationsExists
 *
 * @group checks
 */
final class DenyBuyIfFixationsExistsTest extends KernelTestCase
{
    use SettingsAwareTest;
    use SandboxUnitTester;
    use RateLimiterAwareTest;
    use ChecksAwareTest;
    use StopsTester;

    const string CHECK_ALIAS = DenyBuyIfFixationsExists::ALIAS;

    /**
     * @dataProvider cases
     */
    public function testBuyOrderCanBeExecuted(
        Position $position,
        Ticker $ticker,
        array $stops,
        MarketBuyEntryDto $orderDto,
        AbstractTradingCheckResult $expectedResult,
    ): void {
        $symbol = $ticker->symbol;

        $this->applyDbFixtures(...array_map(static fn (Stop $stop) => new StopFixture($stop), $stops));

        $context = TradingCheckContext::withCurrentPositionState($ticker, $position);
        $orderDto = new MarketBuyCheckDto($orderDto, $ticker);

        // Act
        $result = $this->getCheckService()->check($orderDto, $context);

        // Assert
        self::assertEquals($expectedResult, $result);
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        ### SHORT
        $side = Side::Sell;
        $position = PositionBuilder::bySide($side)->entry(110000)->build();
        $ticker = TickerFactory::withEqualPrices($symbol, 100000);

        // allowed
        $stops = [];
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::SUCCESS($position, $order, $ticker->markPrice);
        yield $result->info() . 'not stops' => [$position, $ticker, $stops, $order, $result];

        $stops = [StopBuilder::short(1, 105000, 0.001, $symbol)->build()];
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::SUCCESS($position, $order, $ticker->markPrice);
        yield $result->info() . 'simple stop' => [$position, $ticker, $stops, $order, $result];

        $stops = [StopBuilder::short(1, 115000, 0.001, $symbol)->build()->setIsStopAfterOtherSymbolLoss()];
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::SUCCESS($position, $order, $ticker->markPrice);
        yield $result->info() . 'no stops between position and ticker 1' => [$position, $ticker, $stops, $order, $result];

        $stops = [StopBuilder::short(1, 99999, 0.001, $symbol)->build()->setIsStopAfterOtherSymbolLoss()];
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::SUCCESS($position, $order, $ticker->markPrice);
        yield $result->info() . 'no stops between position and ticker 2' => [$position, $ticker, $stops, $order, $result];

        // denied
        $stops = [StopBuilder::short(1, 105000, 0.001, $symbol)->build()->setIsStopAfterOtherSymbolLoss()];
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::FAILED(1, $position, $order, $ticker->markPrice, $stops);
        yield $result->info() . 'stop after other symbols loss' => [$position, $ticker, $stops, $order, $result];

        $stops = [StopBuilder::short(1, 105000, 0.001, $symbol)->build()->setStopAfterFixHedgeOppositePositionContest()];
        $order = self::simpleBuyDto($symbol, $side, $ticker->markPrice);
        $result = self::FAILED(1, $position, $order, $ticker->markPrice, $stops);
        yield $result->info() . 'stop after hedge fix' => [$position, $ticker, $stops, $order, $result];
    }

    private static function SUCCESS(
        Position $position,
        MarketBuyEntryDto $order,
        SymbolPrice $orderPrice
    ): TradingCheckResult {
        return TradingCheckResult::succeed(self::CHECK_ALIAS, self::info($position, $order, $orderPrice, 'fixation stops not found'));
    }

    private static function FAILED(
        int $count,
        Position $position,
        MarketBuyEntryDto $order,
        SymbolPrice $orderPrice,
        array $stops,
    ): FixationsFound {
        return FixationsFound::create(
            self::CHECK_ALIAS,
            $count,
            self::info($position, $order, $orderPrice, sprintf('found %d fixation stops', count($stops)))
        );
    }

    private static function info(
        Position $position,
        MarketBuyEntryDto $order,
        SymbolPrice $orderPrice,
        string $reason,
    ): string {
        return sprintf(
            '%s | %s (%s) | entry=%s | %s',
            $position,
            BuyOrderInfoHelper::identifier($order->sourceBuyOrder),
            BuyOrderInfoHelper::shortInlineInfo($order->volume, $orderPrice),
            $position->entryPrice,
            $reason
        );
    }

    private static function simpleBuyDto(SymbolInterface $symbol, Side $side, SymbolPrice $price): MarketBuyEntryDto
    {
        $buyOrder = new BuyOrder(1, $price, 0.005, $symbol, $side);

        return MarketBuyEntryDto::fromBuyOrder($buyOrder);
    }

    private function getCheckService(): DenyBuyIfFixationsExists
    {
        return self::getContainer()->get(DenyBuyIfFixationsExists::class);
    }
}
