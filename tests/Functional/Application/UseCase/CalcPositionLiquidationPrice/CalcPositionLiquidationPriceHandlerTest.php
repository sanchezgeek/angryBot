<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\UseCase\CalcPositionLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Domain\Position;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Coin\CoinAmount;
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
    public function testSimpleCalc(Position $position, float $freeContractBalance, float $expectedLiquidationPrice): void
    {
        $freeContractBalance = new CoinAmount($position->symbol->associatedCoin(), $freeContractBalance);
        $expectedLiquidationDistance = $position->entryPrice()->deltaWith($expectedLiquidationPrice);

        // Act
        $result = $this->handler->handle($position, $freeContractBalance);
        $docsResult = $this->handler->handleFromDocs($position, $freeContractBalance);
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
        $symbol = SymbolEnum::BTCUSDT;
        $freeContractBalance = 20;

        # SHORT
        $position = PositionFactory::short($symbol, 50000, 0.1);
        yield 'BTCUSDT SHORT' => [$position, $freeContractBalance, 50450];

        # LONG
        $position = PositionFactory::long($symbol, 50000, 0.1);
        yield 'BTCUSDT LONG' => [$position, $freeContractBalance, 49550];
    }

    /**
     * @todo | is there hedge case explained in docs?
     *
     * @dataProvider calcForPositionWithSupportTestData
     */
    public function testCalcForPositionWithSupport(Position $mainPosition, float $freeContractBalance, float $expectedLiquidationPrice): void
    {
        $freeContractBalance = new CoinAmount($mainPosition->symbol->associatedCoin(), $freeContractBalance);
        $expectedLiquidationDistance = $mainPosition->entryPrice()->deltaWith($expectedLiquidationPrice);

        // Act
        $result = $this->handler->handle($mainPosition, $freeContractBalance);

        // Assert
        self::assertInstanceOf(CalcPositionLiquidationPriceResult::class, $result);
        self::assertEquals($expectedLiquidationPrice, $result->estimatedLiquidationPrice()->value());
        self::assertEquals($expectedLiquidationDistance, $result->liquidationDistance());
    }

    public function calcForPositionWithSupportTestData(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;

        # SHORT #1
        $main = PositionFactory::short($symbol, 63422.060, 0.374);
        $support = PositionFactory::long($symbol, 60480.590, 0.284);
        $main->setOppositePosition($support); $support->setOppositePosition($main);
        $freeContractBalance = 307.08156;
        yield 'BTCUSDT SHORT #1' => [$main, $freeContractBalance, 76433.16];

        # SHORT #2
        $main = PositionFactory::short($symbol, 63942.450, 0.347);
        $support = PositionFactory::long($symbol, 61191.160, 0.261);
        $main->setOppositePosition($support); $support->setOppositePosition($main);
        $freeContractBalance = -213.21610;
        yield 'BTCUSDT SHORT #2' => [$main, $freeContractBalance, 70132.75];

        # SHORT #3
        $main = PositionFactory::short($symbol, 63532.490, 0.369);
        $support = PositionFactory::long($symbol, 60531.560, 0.281);
        $main->setOppositePosition($support); $support->setOppositePosition($main);
        $freeContractBalance = 5.01147;
        yield 'BTCUSDT SHORT #3' => [$main, $freeContractBalance, 73489.62];

        # LONG #1
        $support = PositionFactory::short($symbol, 63942.450, 0.347);
        $main = PositionFactory::long($symbol, 61191.160, 0.477);
        $main->setOppositePosition($support); $support->setOppositePosition($main);
        $freeContractBalance = 1515.42152;
        yield 'BTCUSDT LONG #1' => [$main, $freeContractBalance, 41884.29];

        # LONG #2
        $support = PositionFactory::short($symbol, 67864.380, 0.431);
        $main = PositionFactory::long($symbol, 63983.600, 0.547);
        $main->setOppositePosition($support); $support->setOppositePosition($main);
        $freeContractBalance = -165.89500;
        yield 'BTCUSDT LONG #2' => [$main, $freeContractBalance, 50674.71];

        # LONG #3
        $support = PositionFactory::short($symbol, 67864.380, 0.410);
        $main = PositionFactory::long($symbol, 63983.600, 0.486);
        $main->setOppositePosition($support); $support->setOppositePosition($main);
        $freeContractBalance = -277.7804;
        yield 'BTCUSDT LONG #3' => [$main, $freeContractBalance, 46382.900];

        # LONG #4
        $support = PositionFactory::short($symbol, 64249.320, 0.222);
        $main = PositionFactory::long($symbol, 58033.500, 0.366);
        $main->setOppositePosition($support); $support->setOppositePosition($main);
        $freeContractBalance = 4.24580;
        yield 'BTCUSDT LONG #4' => [$main, $freeContractBalance, 48131.13];
    }
}
