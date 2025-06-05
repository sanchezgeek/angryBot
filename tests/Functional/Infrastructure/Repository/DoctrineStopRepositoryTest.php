<?php

declare(strict_types=1);

namespace App\Tests\Functional\Infrastructure\Repository;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Assertion\CustomAssertions;
use App\Tests\Fixture\StopFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function uuid_create;

/**
 * @covers \App\Bot\Domain\Repository\BuyOrderRepository
 */
final class DoctrineStopRepositoryTest extends KernelTestCase
{
    use TestWithDbFixtures;
    use BuyOrdersTester;
    use StopsTester;

    private StopRepository $stopRepository;

    protected function setUp(): void
    {
        $this->stopRepository = self::getStopRepository();

        // findFirstStopUnderPosition
        // findFirstPositionStop
        // findPushedToExchange
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testSave(Side $side): void
    {
        // Arrange
        $stop = new Stop(100500, 100500.1, 0.01, 10, SymbolEnum::ADAUSDT, $side, ['someStringContext' => 'some value', 'someArrayContext' => ['value']]);
        self::replaceEnumSymbol($stop);

        $stop->setExchangeOrderId('123456');
        $stop->setIsCloseByMarketContext();

        // Act
        $this->stopRepository->save($stop);

        // Assert
        self::seeStopsInDb(
            new Stop(100500, 100500.1, 0.01, 10, SymbolEnum::ADAUSDT, $side, ['someStringContext' => 'some value', 'someArrayContext' => ['value']])
                ->setIsCloseByMarketContext()
                ->setExchangeOrderId('123456')
        );
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testRemove(Side $side): void
    {
        // Arrange
        $this->applyDbFixtures(
            new StopFixture(new Stop(1, 1050, 123.123, 0.1, SymbolEnum::XRPUSDT, $side)),
            new StopFixture(new Stop(2, 1050, 123.124, 0.2, SymbolEnum::ETHUSDT, $side)),
        );

        $stop = $this->stopRepository->find(2);

        // Act
        $this->stopRepository->remove($stop);

        // Assert
        self::seeStopsInDb(
            new Stop(1, 1050, 123.123, 0.1, SymbolEnum::XRPUSDT, $side)
        );
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanFindActive(Side $side): void
    {
        $this->applyDbFixtures(
            new StopFixture((new Stop(1, 1050, 123.123, 0.1, SymbolEnum::ETHUSDT, $side))->setExchangeOrderId('123456')),
            new StopFixture(new Stop(2, 1050, 123.124, 0.2, SymbolEnum::ADAUSDT, $side)),
            new StopFixture(new Stop(3, 1050, 123.125, 0.3, SymbolEnum::SOLUSDT, $side)),
            new StopFixture(new Stop(4, 1050, 123.125, 0.3, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']])),
        );

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new Stop(4, 1050, 123.125, 0.3, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]),
        ], $this->stopRepository->findActive(SymbolEnum::TONUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new Stop(3, 1050, 123.125, 0.3, SymbolEnum::SOLUSDT, $side),
        ], $this->stopRepository->findActive(SymbolEnum::SOLUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new Stop(2, 1050, 123.124, 0.2, SymbolEnum::ADAUSDT, $side),
        ], $this->stopRepository->findActive(SymbolEnum::ADAUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
        ], $this->stopRepository->findActive(SymbolEnum::ETHUSDT, $side));
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanFindAllByPositionSide(Side $side): void
    {
        $this->applyDbFixtures(
            new StopFixture((new Stop(1, 1050, 123.123, 0.1, SymbolEnum::ETHUSDT, $side))->setExchangeOrderId('123456')),
            new StopFixture(new Stop(2, 1050, 123.124, 0.2, SymbolEnum::ADAUSDT, $side)),
            new StopFixture(new Stop(3, 1050, 123.125, 0.3, SymbolEnum::SOLUSDT, $side)),
            new StopFixture(new Stop(4, 1050, 123.125, 0.3, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']])),
        );

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new Stop(4, 1050, 123.125, 0.3, SymbolEnum::TONUSDT, $side, ['someContext' => 'some value', 'someArrayContext' => ['value']]),
        ], $this->stopRepository->findAllByPositionSide(SymbolEnum::TONUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new Stop(3, 1050, 123.125, 0.3, SymbolEnum::SOLUSDT, $side),
        ], $this->stopRepository->findAllByPositionSide(SymbolEnum::SOLUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new Stop(2, 1050, 123.124, 0.2, SymbolEnum::ADAUSDT, $side),
        ], $this->stopRepository->findAllByPositionSide(SymbolEnum::ADAUSDT, $side));

        CustomAssertions::assertObjectsWithInnerSymbolsEquals([
            new Stop(1, 1050, 123.123, 0.1, SymbolEnum::ETHUSDT, $side)->setExchangeOrderId('123456')
        ], $this->stopRepository->findAllByPositionSide(SymbolEnum::ETHUSDT, $side));
    }

    /**
     * @dataProvider positionSideProvider
     */
    public function testCanFindExchangeOrderId(Side $side): void
    {
        $exchangeOrderId = uuid_create();

        $this->applyDbFixtures(
            new StopFixture(new Stop(1, 100500, 123.123, 10, SymbolEnum::ADAUSDT, $side)),
            new StopFixture((new Stop(100, 2050, 223.1, 20, SymbolEnum::XRPUSDT, $side))->setExchangeOrderId($exchangeOrderId)),
            new StopFixture(new Stop(1000, 3050, 323, 30, SymbolEnum::ETHUSDT, $side)),
        );

        CustomAssertions::assertObjectsWithInnerSymbolsEquals(
            [new Stop(100, 2050, 223.1, 20, SymbolEnum::XRPUSDT, $side)->setExchangeOrderId($exchangeOrderId)],
            [$this->stopRepository->findByExchangeOrderId($side, $exchangeOrderId)]
        );
    }
}
