<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Application\UseCase\Trading\Sandbox\SandboxState;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Factory\Position\PositionBuilder as PB;
use App\Tests\Factory\TickerFactory;
use App\Tests\Helper\ContractBalanceTestHelper;
use App\Tests\Mixin\Assert\PositionsAssertions;
use PHPUnit\Framework\TestCase;

/**
 * @group sandbox
 *
 * @covers \App\Application\UseCase\Trading\Sandbox\SandboxState
 */
class SandboxStateTest extends TestCase
{
    use PositionsAssertions;

    public function testCreate(): void
    {
        $symbol = Symbol::BTCUSDT;
        $coin = $symbol->associatedCoin();

        $ticker = TickerFactory::withEqualPrices($symbol, 68150);
        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->build($long);

        $free = $symbol->associatedCoinAmount(98.1001);

        $contactBalance = ContractBalanceTestHelper::contractBalanceBasedOnFree($free->value(), [$short, $long], $ticker);

        $expectedAvailable = $symbol->associatedCoinAmount(33.9768);

        // Act
        $state = new SandboxState($ticker, $contactBalance, $contactBalance->free, $long, $short);

        // Assert
        self::isPositionsEqual($short, $state->getPosition(Side::Sell));
        self::isPositionsEqual($long, $state->getPosition(Side::Buy));
        self::assertEquals($free, $state->getFreeBalance());
        self::assertEquals($expectedAvailable, $state->getAvailableBalance());
    }

    /**
     * @dataProvider allFreeIsAvailableTestCases
     */
    public function testAllFreeAvailable(Ticker $ticker, float $free, array $positions, float $expectedAvailable): void
    {
        $contactBalance = ContractBalanceTestHelper::contractBalanceBasedOnFree($free, $positions, $ticker);

        $state = new SandboxState($ticker, $contactBalance, $contactBalance->free, ...$positions);

        self::assertEquals($ticker->symbol->associatedCoinAmount($expectedAvailable), $state->getAvailableBalance());
    }

    public function allFreeIsAvailableTestCases(): iterable
    {
        $symbol = Symbol::BTCUSDT;
        $ticker = TickerFactory::withEqualPrices($symbol, 68150);
        $free = 98.1001;

        # available === free
        $expectedAvailable = $free;

        $positions = [];
        yield 'when no positions opened' => [$ticker, $free, $positions, $expectedAvailable];

        $positions = [PB::long()->entry(59426.560)->size(0.084)->build()];
        yield 'LONG in profit' => [$ticker, $free, $positions, $expectedAvailable];

        $positions = [PB::short()->entry(69426.560)->size(0.084)->build()];
        yield 'SHORT in profit' => [$ticker, $free, $positions, $expectedAvailable];

        # One-way mode
        $positions = [PB::short()->entry(68000)->size(0.1)->build()];
        $expectedAvailable = 83.1001;
        yield 'SHORT in loss' => [$ticker, $free, $positions, $expectedAvailable];

        $positions = [PB::long()->entry(68250)->size(0.1)->build()];
        $expectedAvailable = 88.1001;
        yield 'LONG in loss' => [$ticker, $free, $positions, $expectedAvailable];

        # HEDGE (mainPosition = SHORT)
        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(69533.430)->size(0.188)->liq(75361.600)->build($long);
        $positions = [$long, $short];
        $expectedAvailable = $free;
        yield '[hedge] main position in profit' => [$ticker, $free, $positions, $expectedAvailable];

        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->build($long);
        $positions = [$long, $short];
        $expectedAvailable = 33.9768;
        yield '[hedge] main position is loss' => [$ticker, $free, $positions, $expectedAvailable];
    }

    public function testSetClosedPosition(): void
    {
        $symbol = Symbol::BTCUSDT;
        $coin = $symbol->associatedCoin();

        $ticker = TickerFactory::withEqualPrices($symbol, 68150);
        $long = PB::long()->entry(59426.560)->size(0.084)->build();
        $short = PB::short()->entry(67533.430)->size(0.188)->liq(75361.600)->build($long);

        $free = $symbol->associatedCoinAmount(98.1001);
        $contactBalance = ContractBalanceTestHelper::contractBalanceBasedOnFree($free->value(), [$short, $long], $ticker);

        $state = new SandboxState($ticker, $contactBalance, $contactBalance->free, $long, $short);

        // Act
        $state->setPositionAndActualizeOpposite(new ClosedPosition(Side::Sell, $symbol));

        // Assert
        self::assertEquals(null, $state->getPosition(Side::Sell));
        self::assertEquals(PositionClone::clean($long)->create(), $state->getPosition(Side::Buy));
    }
}
