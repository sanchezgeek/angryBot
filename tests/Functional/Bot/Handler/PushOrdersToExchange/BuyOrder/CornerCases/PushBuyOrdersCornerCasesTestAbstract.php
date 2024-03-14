<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\MarketServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Order\Service\OrderCostHelper;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PushBuyOrdersCornerCasesTestAbstract extends KernelTestCase
{
    use BuyOrdersTester;
    use ByBitV5ApiRequestsMocker;
    use TestWithDbFixtures;
    use StopsTester;

    protected const SYMBOL = Symbol::BTCUSDT;

    protected HedgeService $hedgeService;
    protected BuyOrderRepository $buyOrderRepository;
    protected StopRepository $stopRepository;
    protected StopService $stopService;
    protected OrderCostHelper $orderCostHelper;
    protected MarketServiceInterface $marketService;

    protected ExchangeAccountServiceInterface|MockObject $exchangeAccountServiceMock;
    protected OrderServiceInterface|MockObject $orderService;
    protected ExchangeServiceInterface|MockObject $exchangeServiceMock;
    protected PositionServiceInterface|MockObject $positionServiceMock;
    protected LoggerInterface $loggerMock;
    protected ClockInterface $clockMock;

    protected PushBuyOrdersHandler $handler;

    protected function setUp(): void
    {
        $this->hedgeService = self::getContainer()->get(HedgeService::class);
        $this->buyOrderRepository = self::getContainer()->get(BuyOrderRepository::class);
        $this->stopRepository = self::getContainer()->get(StopRepository::class);
        $this->stopService = self::getContainer()->get(StopService::class);
        $this->orderCostHelper = self::getContainer()->get(OrderCostHelper::class);

        $this->exchangeAccountServiceMock = $this->createMock(ExchangeAccountServiceInterface::class);
        $this->marketService = self::getContainer()->get(ByBitMarketService::class);
        $this->orderService = self::getContainer()->get(OrderServiceInterface::class);

        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceMock = $this->createMock(PositionServiceInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->handler = new PushBuyOrdersHandler(
            $this->hedgeService,
            $this->buyOrderRepository,
            $this->stopRepository,
            $this->stopService,
            $this->orderCostHelper,

            $this->exchangeAccountServiceMock,
            $this->marketService,
            $this->orderService,

            $this->exchangeServiceMock,
            $this->positionServiceMock,
            $this->clockMock,
            $this->loggerMock,
        );

        self::truncateBuyOrders();
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }

    protected function havePosition(Position $position): void
    {
        $this->positionServiceMock->method('getPosition')->with($position->symbol, $position->side)->willReturn($position);
    }

    protected function haveAvailableSpotBalance(Symbol $symbol, float $amount): void
    {
        $this->exchangeAccountServiceMock
            ->method('getSpotWalletBalance')
            ->with($coin = $symbol->associatedCoin())
            ->willReturn(
                new WalletBalance(AccountType::SPOT, $coin, $amount, $amount),
            )
        ;
    }

    protected function haveContractWalletBalance(Symbol $symbol, float $total, float $available): void
    {
        $this->exchangeAccountServiceMock
            ->method('getContractWalletBalance')
            ->with($coin = $symbol->associatedCoin())
            ->willReturn(
                new WalletBalance(AccountType::CONTRACT, $coin, $total, $available),
            )
        ;
    }
}
