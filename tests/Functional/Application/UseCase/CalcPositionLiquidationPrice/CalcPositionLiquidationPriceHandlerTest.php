<?php

declare(strict_types=1);

namespace App\Tests\Functional\Application\UseCase\CalcPositionLiquidationPrice;

use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceHandler;
use App\Application\UseCase\Position\CalcPositionLiquidationPrice\CalcPositionLiquidationPriceResult;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Price\Price;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Factory\PositionFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CalcPositionLiquidationPriceHandlerTest extends KernelTestCase
{
    private CalcPositionLiquidationPriceHandler $handler;

    protected function setUp(): void
    {
        $this->handler = self::getContainer()->get(CalcPositionLiquidationPriceHandler::class);
    }

    public function testSimpleCalc(): void
    {
        $symbol = Symbol::BTCUSDT;

        $position = PositionFactory::short($symbol, 50000, 0.1, 100);

        $totalPositionsBalance = $position->initialMargin->value();
        $contractWalletBalance = new WalletBalance(AccountType::CONTRACT, $symbol->associatedCoin(), $totalPositionsBalance, 20);

        $expectedLiquidationPrice = 50450;

        // Act
        $result = $this->handler->handle($position, $contractWalletBalance);
        $docsResult = $this->handler->handleFromDocs($position, $contractWalletBalance);

        // Assert
        $diff = $result->estimatedLiquidationPrice()->value() - $docsResult->estimatedLiquidationPrice()->value();
        self::assertTrue(abs($diff) <= 0.02);
        self::assertEquals(new CalcPositionLiquidationPriceResult(Price::float($expectedLiquidationPrice)), $docsResult);
    }

    /**
     * @todo | is there hedge case explained in docs?
     */
    public function testCalcForPositionWithSupport(): void
    {
        $symbol = Symbol::BTCUSDT;

        $main = PositionFactory::short($symbol, 50000, 0.1, 100);
        $support = PositionFactory::long($symbol, 49000, 0.01, 100);
        $main->setOppositePosition($support);

        $totalPositionsBalance = $main->initialMargin->value() + $support->initialMargin->value();
        $contractWalletBalance = new WalletBalance(AccountType::CONTRACT, $symbol->associatedCoin(), $totalPositionsBalance, 20);

        $expectedLiquidationPrice = 50583.333;

        // Act
        $result = $this->handler->handle($main, $contractWalletBalance);

        // Assert
        self::assertEquals(new CalcPositionLiquidationPriceResult(Price::float($expectedLiquidationPrice)), $result);
    }
}
