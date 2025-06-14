<?php

declare(strict_types=1);

namespace App\Tests\Functional\Modules\Stop\Applicaiton\Handler;

use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Strategy\StopCreate;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Stop\Application\Contract\Command\CreateOppositeStopsAfterBuy;
use App\Tests\Factory\Entity\BuyOrderBuilder;
use App\Tests\Factory\Entity\StopBuilder;
use App\Tests\Factory\PositionFactory;
use App\Tests\Factory\TickerFactory;
use App\Tests\Fixture\BuyOrderFixture;
use App\Tests\Helper\Buy\BuyOrderTestHelper;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\Messenger\MessageConsumerTrait;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group stop
 * @group oppositeOrders
 *
 * @covers \App\Stop\Application\Handler\Command\CreateOppositeStopsAfterBuyCommandHandler
 */
final class CreateOppositeStopsAfterBuyCommandHandlerTest extends KernelTestCase
{
    use MessageConsumerTrait;
    use ByBitV5ApiRequestsMocker;
    use StopsTester;
    use BuyOrdersTester;

    /**
     * @dataProvider cases
     */
    public function testCreateStopsAfterBuy(
        Ticker $ticker,
        Position $position,
        BuyOrder $buyOrder,
        Stop $expectedStop
    ): void {
        $this->haveTicker($ticker);
        $this->havePosition($position->symbol, $position);
        $this->applyDbFixtures(new BuyOrderFixture($buyOrder));

        $this->runMessageConsume(new CreateOppositeStopsAfterBuy($buyOrder->getId()));

        $this->seeStopsInDb($expectedStop);
    }

    public function cases(): iterable
    {
        $symbol = SymbolEnum::BTCUSDT;
        $ticker = TickerFactory::create($symbol, 29050);
        $position = PositionFactory::short($symbol, 29000, 0.01, 100, 35000);

        yield 'create BTCUSDT SHORT Stop' => [
            $ticker,
            $position,
            $buyOrder = BuyOrderTestHelper::setActive(BuyOrderBuilder::short(10, 29060, 0.01)->build()),
            StopBuilder::short(1, self::expectedStopPrice($buyOrder), $buyOrder->getVolume())->build(),
        ];
    }

    private static function expectedStopPrice(BuyOrder $buyOrder): float
    {
        $stopDistance = StopCreate::getDefaultStrategyStopOrderDistance($buyOrder->getVolume());

        return $buyOrder->getPrice() + ($buyOrder->getPositionSide()->isShort() ? $stopDistance : -$stopDistance);
    }

    public function testDummy(): void
    {
        self::markTestIncomplete('cases: short_stop, ...');
    }
}
