<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\UseCase\CalcPositionLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Price\Price;
use App\Helper\FloatHelper;
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
    public function testSimpleCalc(Position $position, float $totalContractBalance, float $expectedLiquidationPrice): void
    {
        $totalContractBalance = new CoinAmount($position->symbol->associatedCoin(), $totalContractBalance);
        $expectedLiquidationDistance = FloatHelper::round(Price::float($position->entryPrice)->deltaWith($expectedLiquidationPrice));

        // Act
        $result = $this->handler->handle($position, $totalContractBalance);
        $docsResult = $this->handler->handleFromDocs($position, $totalContractBalance);
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
        $totalContractBalance = $position->initialMargin->value() + 20;
        yield 'BTCUSDT SHORT' => [$position, $totalContractBalance, 50450];

        # LONG
        $position = PositionFactory::long($symbol, 50000, 0.1, 100);
        $totalContractBalance = $position->initialMargin->value() + 20;
        yield 'BTCUSDT LONG' => [$position, $totalContractBalance, 49550];
    }

    /**
     * @todo | is there hedge case explained in docs?
     *
     * @dataProvider calcForPositionWithSupportTestData
     */
    public function testCalcForPositionWithSupport(Position $mainPosition, float $totalContractBalance, float $expectedLiquidationPrice): void
    {
        $expectedLiquidationDistance = FloatHelper::round(Price::float($mainPosition->entryPrice)->deltaWith($expectedLiquidationPrice));

        // Act
        $result = $this->handler->handle($mainPosition, new CoinAmount($mainPosition->symbol->associatedCoin(), $totalContractBalance));

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

        $totalContractBalance = $main->initialMargin->value() + $support->initialMargin->value() + 20;
        yield 'BTCUSDT SHORT' => [$main, $totalContractBalance, 50588.89];

        # LONG
        $main = PositionFactory::long($symbol, 50000, 0.1, 100);
        $support = PositionFactory::short($symbol, 51000, 0.01, 100);
        $main->setOppositePosition($support); $support->setOppositePosition($main);

        $totalContractBalance = $main->initialMargin->value() + $support->initialMargin->value() + 20;
        yield 'BTCUSDT LONG' => [$main, $totalContractBalance, 49411.11];
    }
}
