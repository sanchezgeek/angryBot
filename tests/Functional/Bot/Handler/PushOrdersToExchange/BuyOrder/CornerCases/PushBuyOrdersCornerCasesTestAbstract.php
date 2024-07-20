<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Application\UseCase\Trading\MarketBuy\MarketBuyHandler;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Order\Service\OrderCostCalculator;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PushBuyOrdersCornerCasesTestAbstract extends KernelTestCase
{
    use BuyOrdersTester;
    use ByBitV5ApiRequestsMocker;
    use TestWithDbFixtures;
    use StopsTester;

    protected const SYMBOL = Symbol::BTCUSDT;

    protected PushBuyOrdersHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new PushBuyOrdersHandler(
            self::getContainer()->get(CreateBuyOrderHandler::class),
            self::getContainer()->get(HedgeService::class),
            self::getContainer()->get(BuyOrderRepository::class),
            self::getContainer()->get(StopRepository::class),
            self::getContainer()->get(StopService::class),
            self::getContainer()->get(OrderCostCalculator::class),

            self::getContainer()->get(ExchangeAccountServiceInterface::class),
            self::getContainer()->get(ByBitMarketService::class),
            self::getContainer()->get(OrderServiceInterface::class),
            self::getContainer()->get(MarketBuyHandler::class),

            self::getContainer()->get(ExchangeServiceInterface::class),
            self::getContainer()->get(PositionServiceInterface::class),
            $this->createMock(ClockInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        self::truncateBuyOrders();
    }
}
