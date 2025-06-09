<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\ButOrder\ResetBuyOrdersActiveState;

use App\Bot\Application\Messenger\Job\BuyOrder\ResetBuyOrdersActiveState\ResetBuyOrdersActiveState;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Helper\Buy\BuyOrderTestHelper;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Clock\ClockTimeAwareTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\OrderCasesTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers ResetBuyOrdersActiveStateHandler
 */
final class ResetBuyOrdersActiveStateHandlerTest extends KernelTestCase
{
    use OrderCasesTester;
    use BuyOrdersTester;
    use ByBitV5ApiRequestsMocker;
    use MessageConsumerTrait;
    use ClockTimeAwareTester;

    /**
     * @dataProvider activeOrdersBecameIdleTestDataProvider
     *
     * @param BuyOrder[] $buyOrdersExpectedAfterHandle
     */
    public function testIdleOrdersBecameActive(
        array $buyOrdersFixtures,
        array $tickers,
        array $buyOrdersExpectedAfterHandle,
    ): void {
        foreach ($tickers as $symbolRaw => $price) {
            $this->haveTicker(TickerFactory::withEqualPrices(SymbolEnum::from($symbolRaw), $price));
        }
        $this->applyDbFixtures(...$buyOrdersFixtures);

        $this->runMessageConsume(new ResetBuyOrdersActiveState());

        self::seeBuyOrdersInDb(...$buyOrdersExpectedAfterHandle);
    }

    public function activeOrdersBecameIdleTestDataProvider(): iterable
    {
        $tickers = [
            SymbolEnum::BTCUSDT->value => 100000,
            SymbolEnum::ETHUSDT->value => 2000,
        ];

        $buyOrders = [
            # short BTCUSDT
            10 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::short(10, 100501, 0.01)->build()), // must be set idle (expired + not in applicable range)
            15 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(15, 100501, 0.01)->build()), // must be active at current time
            20 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::short(20, 100500, 0.01)->build()), // inside applicable range

            # long BTCUSDT
            30 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::long(30, 99499, 0.01)->build()),
            35 => BuyOrderTestHelper::setActive(BuyOrderBuilder::long(35, 99499, 0.01)->build()),
            40 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::long(40, 99501, 0.01)->build()),

            # short ETHUSDT
            50 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::short(50, 2021, 0.01, SymbolEnum::ETHUSDT)->build()),
            55 => BuyOrderTestHelper::setActive(BuyOrderBuilder::short(55, 2021, 0.01, SymbolEnum::ETHUSDT)->build()),
            60 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::short(60, 2020, 0.01, SymbolEnum::ETHUSDT)->build()),

            # long ETHUSDT
            70 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::long(70, 1979, 0.01, SymbolEnum::ETHUSDT)->build()),
            75 => BuyOrderTestHelper::setActive(BuyOrderBuilder::long(75, 1979, 0.01, SymbolEnum::ETHUSDT)->build()),
            80 => BuyOrderTestHelper::setActiveThatMustBeResetAtCurrentTime(BuyOrderBuilder::long(80, 1980, 0.01, SymbolEnum::ETHUSDT)->build()),
        ];

        yield [
            '$buyOrdersFixtures' => array_map(static fn(BuyOrder $buyOrder) => new BuyOrderFixture($buyOrder), $buyOrders),
            '$tickers' => $tickers,
            'buyOrdersExpectedAfterHandle' => [
                BuyOrderTestHelper::clone($buyOrders[10])->setIdle(),
                BuyOrderTestHelper::clone($buyOrders[15]),
                BuyOrderTestHelper::clone($buyOrders[20]),

                BuyOrderTestHelper::clone($buyOrders[30])->setIdle(),
                BuyOrderTestHelper::clone($buyOrders[35]),
                BuyOrderTestHelper::clone($buyOrders[40]),

                BuyOrderTestHelper::clone($buyOrders[50])->setIdle(),
                BuyOrderTestHelper::clone($buyOrders[55]),
                BuyOrderTestHelper::clone($buyOrders[60]),

                BuyOrderTestHelper::clone($buyOrders[70])->setIdle(),
                BuyOrderTestHelper::clone($buyOrders[75]),
                BuyOrderTestHelper::clone($buyOrders[80]),
            ],
        ];
    }
}
