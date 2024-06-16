<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\UseCase\CalcPositionLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use App\Helper\FloatHelper;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Factory\PositionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function abs;

class CalcPositionLiquidationPriceHandlerTest extends KernelTestCase
{
    private CalcPositionLiquidationPriceHandler $handler;

    protected function setUp(): void
    {
        $this->handler = self::getContainer()->get(CalcPositionLiquidationPriceHandler::class);
    }

    /**
     * @dataProvider simpleCalcTestData
     */
    public function testSimpleCalc(Position $position, WalletBalance $contractWalletBalance, float $expectedLiquidationPrice): void
    {
        $expectedLiquidationDistance = FloatHelper::round(Price::float($position->entryPrice)->deltaWith($expectedLiquidationPrice));

        // Act
        $result = $this->handler->handle($position, $contractWalletBalance);
        $docsResult = $this->handler->handleFromDocs($position, $contractWalletBalance);
        self::assertInstanceOf(CalcPositionLiquidationPriceResult::class, $result);
        self::assertInstanceOf(CalcPositionLiquidationPriceResult::class, $docsResult);

        // Assert
        # difference between `calc by ByBit docs` and manual calc
        self::assertTrue($result->estimatedLiquidationPrice()->deltaWith($docsResult->estimatedLiquidationPrice()) <= 0.02);
        self::assertTrue(abs($result->liquidationDistance() - $docsResult->liquidationDistance()) <= 0.02);

        self::assertEquals($expectedLiquidationPrice, $docsResult->estimatedLiquidationPrice()->value());
        self::assertEquals($expectedLiquidationDistance, $docsResult->liquidationDistance());
    }

    public function simpleCalcTestData(): iterable
    {
        $symbol = Symbol::BTCUSDT;

        # SHORT
        $position = PositionFactory::short($symbol, 50000, 0.1, 100);

        $totalPositionsBalance = $position->initialMargin->value() + 20;
        $contractWalletBalance = new WalletBalance(AccountType::CONTRACT, $symbol->associatedCoin(), $totalPositionsBalance, 20);

        yield 'BTCUSDT SHORT' => [$position, $contractWalletBalance, 50450];

        # LONG
        $position = PositionFactory::long($symbol, 50000, 0.1, 100);

        $totalPositionsBalance = $position->initialMargin->value() + 20;
        $contractWalletBalance = new WalletBalance(AccountType::CONTRACT, $symbol->associatedCoin(), $totalPositionsBalance, 20);

        yield 'BTCUSDT LONG' => [$position, $contractWalletBalance, 49550];
    }

    /**
     * @todo | is there hedge case explained in docs?
     *
     * @dataProvider calcForPositionWithSupportTestData
     */
    public function testCalcForPositionWithSupport(Position $mainPosition, WalletBalance $contractWalletBalance, float $expectedLiquidationPrice): void
    {
        $expectedLiquidationDistance = FloatHelper::round(Price::float($mainPosition->entryPrice)->deltaWith($expectedLiquidationPrice));

        // Act
        $result = $this->handler->handle($mainPosition, $contractWalletBalance);

        // Assert
        self::assertInstanceOf(CalcPositionLiquidationPriceResult::class, $result);
        self::assertEquals($expectedLiquidationPrice, $result->estimatedLiquidationPrice()->value());
        self::assertEquals($expectedLiquidationDistance, FloatHelper::round($result->liquidationDistance()));
    }

    public function calcForPositionWithSupportTestData(): iterable
    {
        $symbol = Symbol::BTCUSDT;

        # SHORT
        $main = PositionFactory::short($symbol, 50000, 0.1, 100);
        $support = PositionFactory::long($symbol, 49000, 0.01, 100);
        $main->setOppositePosition($support); $support->setOppositePosition($main);

        $totalPositionsBalance = $main->initialMargin->value() + $support->initialMargin->value() + 20;
        $contractWalletBalance = new WalletBalance(AccountType::CONTRACT, $symbol->associatedCoin(), $totalPositionsBalance, 20);

        yield 'BTCUSDT SHORT' => [$main, $contractWalletBalance, 50583.33];

        # LONG
        $main = PositionFactory::long($symbol, 50000, 0.1, 100);
        $support = PositionFactory::short($symbol, 51000, 0.01, 100);
        $main->setOppositePosition($support); $support->setOppositePosition($main);

        $totalPositionsBalance = $main->initialMargin->value() + $support->initialMargin->value() + 20;
        $contractWalletBalance = new WalletBalance(AccountType::CONTRACT, $symbol->associatedCoin(), $totalPositionsBalance, 20);

        yield 'BTCUSDT LONG' => [$main, $contractWalletBalance, 49416.67];
    }
}
