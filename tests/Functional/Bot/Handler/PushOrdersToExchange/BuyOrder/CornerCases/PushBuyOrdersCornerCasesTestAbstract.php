<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Exchange\Trade\OrderServiceInterface;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\BuyOrderRepository;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\TestWithDbFixtures;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PushBuyOrdersCornerCasesTestAbstract extends KernelTestCase
{
    protected const USE_SPOT_IF_BALANCE_GREATER_THAN = PushBuyOrdersHandler::USE_SPOT_IF_BALANCE_GREATER_THAN;

    use TestWithDbFixtures;
    use StopsTester;
    use BuyOrdersTester;

    protected const SYMBOL = Symbol::BTCUSDT;

    protected BuyOrderRepository $buyOrderRepository;
    protected StopRepository $stopRepository;
    protected StopService $stopService;

    protected ExchangeAccountServiceInterface|MockObject $exchangeAccountServiceMock;
    protected OrderServiceInterface|MockObject $orderServiceMock;
    protected ExchangeServiceInterface|MockObject $exchangeServiceMock;
    protected PositionServiceInterface|MockObject $positionServiceMock;
    protected LoggerInterface $loggerMock;
    protected ClockInterface $clockMock;

    protected PushBuyOrdersHandler $handler;

    protected function setUp(): void
    {
        $this->buyOrderRepository = self::getContainer()->get(BuyOrderRepository::class);
        $this->stopRepository = self::getContainer()->get(StopRepository::class);
        $this->stopService = self::getContainer()->get(StopService::class);

        $this->exchangeAccountServiceMock = $this->createMock(ExchangeAccountServiceInterface::class);
        $this->orderServiceMock = $this->createMock(OrderServiceInterface::class);
        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceMock = $this->createMock(PositionServiceInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);

        $this->handler = new PushBuyOrdersHandler(
            $this->buyOrderRepository,
            $this->stopRepository,
            $this->stopService,
            $this->exchangeAccountServiceMock,
            $this->orderServiceMock,
            $this->exchangeServiceMock,
            $this->positionServiceMock,
            $this->loggerMock,
            $this->clockMock
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

    protected function haveSpotBalance(Symbol $symbol, float $value): void
    {
        $this->exchangeAccountServiceMock
            ->expects(self::once())
            ->method('getSpotWalletBalance')
            ->with($coin = $symbol->associatedCoin())
            ->willReturn(
                new WalletBalance(AccountType::SPOT, $coin, $value),
            )
        ;
    }
}
