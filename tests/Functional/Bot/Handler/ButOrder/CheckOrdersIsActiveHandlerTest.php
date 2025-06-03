<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\ButOrder;

use App\Bot\Application\Messenger\Job\BuyOrder\CheckOrdersNowIsActive;
use App\Bot\Application\Messenger\Job\BuyOrder\CheckOrdersNowIsActiveHandler;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Infrastructure\ByBit\API\Common\Emun\Asset\AssetCategory;
use App\Infrastructure\ByBit\API\V5\Request\Position\GetPositionsRequest;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\Tester\ByBitApiRequests\ByBitApiCallExpectation;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mock\Response\ByBitV5Api\PositionResponseBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CheckOrdersIsActiveHandlerTest extends KernelTestCase
{
    use OrderCasesTester;
    use BuyOrdersTester;
    use ByBitV5ApiRequestsMocker;

    private CheckOrdersNowIsActiveHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        self::truncateBuyOrders();

        $this->handler = self::getContainer()->get(CheckOrdersNowIsActiveHandler::class);
    }

    /**
     * @dataProvider idleOrdersBecameActiveTestDataProvider
     */
    public function testIdleOrdersBecameActive(
        array $buyOrdersFixtures,
        array $positions,
        array $buyOrdersExpectedAfterHandle,
    ): void {
        $this->haveAllOpenedPositionsWithLastMarkPrices($positions);
        $this->applyDbFixtures(...$buyOrdersFixtures);

        ($this->handler)(new CheckOrdersNowIsActive());

        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
    }

    public function idleOrdersBecameActiveTestDataProvider(): iterable
    {
        $positions = [
            30000 => PositionFactory::short(SymbolEnum::BTCUSDT, 29000, 0.01),
            2140 => PositionFactory::short(SymbolEnum::ETHUSDT, 2000, 0.01),
        ];

        $buyOrders = [
            BuyOrderBuilder::short(10, 30001, 0.01)->build(),
            BuyOrderBuilder::short(20, 29999, 0.011)->build(),

            BuyOrderBuilder::long(30, 30001, 0.01)->build(),
            BuyOrderBuilder::long(40, 29999, 0.011)->build(),

            BuyOrderBuilder::short(50, 2141, 0.01, SymbolEnum::ETHUSDT)->build(),
            BuyOrderBuilder::short(60, 2139, 0.11, SymbolEnum::ETHUSDT)->build(),

            BuyOrderBuilder::long(70, 2141, 0.01, SymbolEnum::ETHUSDT)->build(),
            BuyOrderBuilder::long(80, 2139, 0.11, SymbolEnum::ETHUSDT)->build(),
        ];

        yield [
            '$buyOrdersFixtures' => array_map(static fn(BuyOrder $buyOrder) => new BuyOrderFixture($buyOrder), $buyOrders),
            '$positions' => $positions,
            'buyOrdersExpectedAfterHandle' => [
                BuyOrderBuilder::short(10, 30001, 0.01)->build()->setIdle(),
                BuyOrderBuilder::short(20, 29999, 0.011)->build()->setActive(),

                BuyOrderBuilder::long(30, 30001, 0.01)->build()->setActive(),
                BuyOrderBuilder::long(40, 29999, 0.011)->build()->setIdle(),

                BuyOrderBuilder::short(50, 2141, 0.01, SymbolEnum::ETHUSDT)->build()->setIdle(),
                BuyOrderBuilder::short(60, 2139, 0.11, SymbolEnum::ETHUSDT)->build()->setActive(),

                BuyOrderBuilder::long(70, 2141, 0.01, SymbolEnum::ETHUSDT)->build()->setActive(),
                BuyOrderBuilder::long(80, 2139, 0.11, SymbolEnum::ETHUSDT)->build()->setIdle(),
            ],
        ];
    }
}
