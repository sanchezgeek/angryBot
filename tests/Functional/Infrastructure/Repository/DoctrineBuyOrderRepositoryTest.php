<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Repository;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Assertion\CustomAssertions;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\TestWithDbFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function uuid_create;

/**
 * @covers \App\Bot\Domain\Repository\BuyOrderRepository
 */
final class DoctrineBuyOrderRepositoryTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use BuyOrdersTester;

    /**
     * @see \App\Tests\Unit\Domain\Entity\BuyOrderTest For contexts
     *
     * @dataProvider positionSideProvider
     */
    public function testSave(Side $side): void
    {
        // Arrange
        $buyOrder = new BuyOrder(1, 100500, 123.456, SymbolEnum::ADAUSDT, $side, ['someStringContext' => 'some value', 'someArrayContext' => ['value']]);
        $buyOrder->setExchangeOrderId(
            $buyOrderExchangeOrderId = uuid_create()
        );
        $buyOrder->setOnlyAfterExchangeOrderExecutedContext(
            $stopExchangeOrderId = uuid_create()
        );
        self::replaceEnumSymbol($buyOrder);

        // Act
        self::getBuyOrderRepository()->save($buyOrder);

        // Assert
        self::seeBuyOrdersInDb(
            new BuyOrder(1, 100500, 123.456, SymbolEnum::ADAUSDT, $side, ['someStringContext' => 'some value', 'someArrayContext' => ['value']])
                ->setExchangeOrderId($buyOrderExchangeOrderId)
                ->setOnlyAfterExchangeOrderExecutedContext($stopExchangeOrderId)
        );
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testRemove(Side $side): void
    {
        // Arrange
        $this->applyDbFixtures(
            new BuyOrderFixture(new BuyOrder(1, 1050, 123.123, SymbolEnum::XRPUSDT, $side)),
            new BuyOrderFixture(new BuyOrder(2, 1050, 123.123, SymbolEnum::XRPUSDT, $side)),
        );

        $buyOrder = self::getBuyOrderRepository()->find(2);

        // Act
        self::getBuyOrderRepository()->remove($buyOrder);

        // Assert
        self::seeBuyOrdersInDb(
            new BuyOrder(1, 1050, 123.123, SymbolEnum::XRPUSDT, $side)
        );
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanFindActive(Side $side): void
    {
        $this->applyDbFixtures(
            new BuyOrderFixture(new BuyOrder(1, 1050, 123.123, SymbolEnum::ETHUSDT, $side)->setExchangeOrderId('123456')),
            new BuyOrderFixture(new BuyOrder(2, 1050, 123.123, SymbolEnum::ADAUSDT, $side)),
            new BuyOrderFixture(new BuyOrder(3, 2050, 223.1, SymbolEnum::SOLUSDT, $side)),
            new BuyOrderFixture((new BuyOrder(4, 3050, 323, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]))),
        );

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new BuyOrder(4, 3050, 323, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]),
        ], self::getBuyOrderRepository()->findActive(SymbolEnum::TONUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new BuyOrder(3, 2050, 223.1, SymbolEnum::SOLUSDT, $side),
        ], self::getBuyOrderRepository()->findActive(SymbolEnum::SOLUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new BuyOrder(2, 1050, 123.123, SymbolEnum::ADAUSDT, $side),
        ], self::getBuyOrderRepository()->findActive(SymbolEnum::ADAUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
        ], self::getBuyOrderRepository()->findActive(SymbolEnum::ETHUSDT, $side));
    }

    public function testCanFindActiveInRangeForLong(): void
    {
        $side = Side::Buy;

        $this->applyDbFixtures(
            new BuyOrderFixture(new BuyOrder(1, 100500, 123.123, SymbolEnum::ADAUSDT, $side)),
            new BuyOrderFixture((new BuyOrder(100, 2050, 223.1, SymbolEnum::ETHUSDT, $side))->setExchangeOrderId('123456')),
            new BuyOrderFixture((new BuyOrder(101, 2050, 223.1, SymbolEnum::ADAUSDT, $side))->setActive()),
            new BuyOrderFixture((new BuyOrder(1000, 1999, 323, SymbolEnum::ADAUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]))->setActive()),
            new BuyOrderFixture((new BuyOrder(2000, 1999, 323.1, SymbolEnum::ADAUSDT, $side))->setIdle()),
        );

        CustomAssertions::assertObjectsWithInnerSymbolsEquals(
            [(new BuyOrder(1000, 1999, 323, SymbolEnum::ADAUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]))->setActive()],
            self::getBuyOrderRepository()->findActiveForPush(SymbolEnum::ADAUSDT, $side, 2000)
        );
    }

    public function testCanFindActiveInRangeForShort(): void
    {
        $side = Side::Sell;

        $this->applyDbFixtures(
            new BuyOrderFixture(new BuyOrder(1, 100500, 123.123, SymbolEnum::ADAUSDT, $side)),
            new BuyOrderFixture((new BuyOrder(100, 2050, 223.1, SymbolEnum::ETHUSDT, $side))->setExchangeOrderId('123456')),
            new BuyOrderFixture((new BuyOrder(101, 2060, 221.1, SymbolEnum::ADAUSDT, $side))),
            new BuyOrderFixture((new BuyOrder(102, 2050, 223.1, SymbolEnum::ADAUSDT, $side))->setActive()),
            new BuyOrderFixture((new BuyOrder(1000, 1999, 323, SymbolEnum::ADAUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]))->setActive()),
            new BuyOrderFixture((new BuyOrder(2000, 1999, 323.1, SymbolEnum::ADAUSDT, $side))->setActive()),
        );

        CustomAssertions::assertObjectsWithInnerSymbolsEquals(
            [(new BuyOrder(102, 2050, 223.1, SymbolEnum::ADAUSDT, $side))->setActive()],
            self::getBuyOrderRepository()->findActiveForPush(SymbolEnum::ADAUSDT, $side, 2049)
        );
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanFindOppositeToStopByExchangeOrderId(Side $side): void
    {
        $exchangeOrderId = uuid_create();

        $this->applyDbFixtures(
            new BuyOrderFixture(new BuyOrder(1, 100500, 123.123, SymbolEnum::ADAUSDT, $side)),
            new BuyOrderFixture((new BuyOrder(100, 2050, 223.1, SymbolEnum::XRPUSDT, $side))->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId)),
            new BuyOrderFixture(new BuyOrder(1000, 3050, 323, SymbolEnum::ETHUSDT, $side)),
        );

        CustomAssertions::assertObjectsWithInnerSymbolsEquals(
            [(new BuyOrder(100, 2050, 223.1, SymbolEnum::XRPUSDT, $side))->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId)],
            self::getBuyOrderRepository()->findOppositeToStopByExchangeOrderId($side, $exchangeOrderId)
        );
    }
}
