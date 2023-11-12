<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\UseCase\CalcPositionLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceEntryDto;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Position\Liquidation\PositionLiquidationTrace\CoinAmount;
use App\Domain\Price\Helper\PriceHelper;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use App\Tests\Factory\PositionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CalcPositionLiquidationPriceHandlerTest extends KernelTestCase
{
    private CalcPositionLiquidationPriceHandler $handler;

    protected function setUp(): void
    {
        $this->handler = self::getContainer()->get(CalcPositionLiquidationPriceHandler::class);
    }

    public function testCalcLiqPrice(): void
    {
        // Arrange
        $symbol = Symbol::BTCUSDT;

        $usdtDeposit = new CoinAmount($symbol->associatedCoin(), 28.5);

        $size = 0.057;
        $entry = 34432;
        $leverage = 100;

        $position = PositionFactory::short($symbol, $entry, $size, $leverage);
        $openPositionCommission = Percent::string('10%')->of($position->initialMargin);
        $contractBalanceAfterOpenPosition = $usdtDeposit->sub($openPositionCommission);

        $entryDto = new CalcPositionLiquidationPriceEntryDto($position, $contractBalanceAfterOpenPosition);

        $expectedLiquidationPrice = $entry + ($entry / $leverage / 2) + $contractBalanceAfterOpenPosition->sub($position->initialMargin)->value() / $position->size; // 34725.41

        // Act
        $result = $this->handler->handle($entryDto);
        $docsResult = $this->handler->handleFromDocs($entryDto);

        // Assert
        $diff = $result->estimatedLiquidationPrice()->value() - $docsResult->estimatedLiquidationPrice()->value();
        self::assertTrue(abs($diff) <= 0.02);
        self::assertEquals(34725.41, PriceHelper::round($expectedLiquidationPrice));
        self::assertEquals(new CalcPositionLiquidationPriceResult(Price::float($expectedLiquidationPrice)), $docsResult);
    }
}
