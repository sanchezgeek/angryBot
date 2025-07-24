<?php

declare(strict_types=1);

namespace App\Tests\Functional\Domain\Entity;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\DataProvider\PositionSideAwareTest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers \App\Bot\Domain\Entity\BuyOrder
 */
final class BuyOrderTest extends KernelTestCase
{
    use PositionSideAwareTest;
    use BuyOrdersTester;

    private BuyOrderRepository $buyOrderRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->buyOrderRepository = self::getContainer()->get(BuyOrderRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function saveOrder(BuyOrder $buyOrder): void
    {
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));
        $this->entityManager->clear();
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testGetStopDistance(Side $side): void
    {
        $this->saveOrder(
            new BuyOrder(1, 100500, 123.456, SymbolEnum::ADAUSDT, $side)
        );
        self::assertNull($this->buyOrderRepository->find(1)->getOppositeOrderDistance());

        $this->saveOrder(
            new BuyOrder(2, 100500, 123.456, SymbolEnum::ADAUSDT, $side, [BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT => 100500])
        );
        self::assertSame(100500.0, $this->buyOrderRepository->find(2)->getOppositeOrderDistance());

        $this->saveOrder(
            new BuyOrder(3, 100500, 123.456, SymbolEnum::ADAUSDT, $side, [BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT => 100500.1])
        );
        self::assertSame(100500.1, $this->buyOrderRepository->find(3)->getOppositeOrderDistance());

        $this->saveOrder(
            new BuyOrder(4, 100500, 123.456, SymbolEnum::ADAUSDT, $side)->setOppositeOrdersDistance(123.1)
        );
        self::assertSame(123.1, $this->buyOrderRepository->find(4)->getOppositeOrderDistance());

        $this->saveOrder(
            new BuyOrder(5, 100500, 123.456, SymbolEnum::ADAUSDT, $side)->setOppositeOrdersDistance(Percent::notStrict(123000.1))
        );
        self::assertEquals(Percent::notStrict(123000.1), $this->buyOrderRepository->find(5)->getOppositeOrderDistance());

        $this->saveOrder(
            new BuyOrder(6, 100500, 123.456, SymbolEnum::ADAUSDT, $side, [BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT => '100500.1%'])
        );
        self::assertEquals(Percent::notStrict(100500.1), $this->buyOrderRepository->find(6)->getOppositeOrderDistance());

        $this->saveOrder(
            new BuyOrder(7, 100500, 123.456, SymbolEnum::ADAUSDT, $side, [BuyOrder::OPPOSITE_ORDERS_DISTANCE_CONTEXT => Percent::notStrict(100500.5)])
        );
        self::assertEquals(Percent::notStrict(100500.5), $this->buyOrderRepository->find(7)->getOppositeOrderDistance());
    }
}
