<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Repository;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
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

    private BuyOrderRepository $buyOrderRepository;

    protected function setUp(): void
    {
        $this->buyOrderRepository = self::getBuyOrderRepository();

        self::truncateBuyOrders();
    }

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

        // Act
        $this->buyOrderRepository->save($buyOrder);

        // Assert
        self::seeBuyOrdersInDb(
            (new BuyOrder(1, 100500, 123.456, SymbolEnum::ADAUSDT, $side, ['someStringContext' => 'some value', 'someArrayContext' => ['value']]))
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

        $buyOrder = $this->buyOrderRepository->find(2);

        // Act
        $this->buyOrderRepository->remove($buyOrder);

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
            new BuyOrderFixture((new BuyOrder(1, 1050, 123.123, SymbolEnum::ETHUSDT, $side))->setExchangeOrderId('123456')),
            new BuyOrderFixture(new BuyOrder(2, 1050, 123.123, SymbolEnum::ADAUSDT, $side)),
            new BuyOrderFixture(new BuyOrder(3, 2050, 223.1, SymbolEnum::SOLUSDT, $side)),
            new BuyOrderFixture((new BuyOrder(4, 3050, 323, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]))),
        );

        self::assertEquals([
            new BuyOrder(4, 3050, 323, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]),
        ], $this->buyOrderRepository->findActive(SymbolEnum::TONUSDT, $side));

        self::assertEquals([
            new BuyOrder(3, 2050, 223.1, SymbolEnum::SOLUSDT, $side),
        ], $this->buyOrderRepository->findActive(SymbolEnum::SOLUSDT, $side));

        self::assertEquals([
            new BuyOrder(2, 1050, 123.123, SymbolEnum::ADAUSDT, $side),
        ], $this->buyOrderRepository->findActive(SymbolEnum::ADAUSDT, $side));

        self::assertEquals([
        ], $this->buyOrderRepository->findActive(SymbolEnum::ETHUSDT, $side));
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

        self::assertEquals(
            [(new BuyOrder(1000, 1999, 323, SymbolEnum::ADAUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]))->setActive()],
            $this->buyOrderRepository->findActiveForPush(SymbolEnum::ADAUSDT, $side, 2000)
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

        self::assertEquals(
            [(new BuyOrder(102, 2050, 223.1, SymbolEnum::ADAUSDT, $side))->setActive()],
            $this->buyOrderRepository->findActiveForPush(SymbolEnum::ADAUSDT, $side, 2049)
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

        self::assertEquals(
            [(new BuyOrder(100, 2050, 223.1, SymbolEnum::XRPUSDT, $side))->setOnlyAfterExchangeOrderExecutedContext($exchangeOrderId)],
            $this->buyOrderRepository->findOppositeToStopByExchangeOrderId($side, $exchangeOrderId)
        );
    }
}
