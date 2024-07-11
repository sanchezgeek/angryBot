<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange\BuyOrder\CornerCases;

use App\Application\UseCase\BuyOrder\Create\CreateBuyOrderHandler;
use App\Bot\Application\Messenger\Job\PushOrdersToExchange\PushBuyOrdersHandler;
use App\Bot\Application\Service\Exchange\Account\AbstractExchangeAccountService;
use App\Bot\Application\Service\Exchange\Account\ExchangeAccountServiceInterface;
use App\Bot\Application\Service\Exchange\Dto\WalletBalance;
use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
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
use App\Domain\Order\Service\OrderCostCalculator;
use App\Infrastructure\ByBit\API\V5\Enum\Account\AccountType;
use App\Infrastructure\ByBit\Service\ByBitMarketService;
use App\Tests\Mixin\BuyOrdersTester;
use App\Tests\Mixin\StopsTester;
use App\Tests\Mixin\Tester\ByBitV5ApiRequestsMocker;
use App\Tests\Mixin\TestWithDbFixtures;
use Mockery;
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

    protected OrderCostCalculator $orderCostCalculator;
    protected ExchangeAccountServiceInterface|Mockery\MockInterface $exchangeAccountServiceMock;
    protected ExchangeServiceInterface|MockObject $exchangeServiceMock;
    protected PositionServiceInterface|MockObject $positionServiceMock;
    protected LoggerInterface $loggerMock;
    protected ClockInterface $clockMock;

    protected PushBuyOrdersHandler $handler;

    protected function setUp(): void
    {
        /** @var OrderCostCalculator $orderCostCalculator */
        $orderCostCalculator = self::getContainer()->get(OrderCostCalculator::class);
        $this->orderCostCalculator = $orderCostCalculator;
        $this->exchangeAccountServiceMock = Mockery::mock(AbstractExchangeAccountService::class)->makePartial();

        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceMock = $this->createMock(PositionServiceInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->handler = new PushBuyOrdersHandler(
            self::getContainer()->get(CreateBuyOrderHandler::class),
            self::getContainer()->get(HedgeService::class),
            self::getContainer()->get(BuyOrderRepository::class),
            self::getContainer()->get(StopRepository::class),
            self::getContainer()->get(StopService::class),
            $this->orderCostCalculator,

            $this->exchangeAccountServiceMock,
            self::getContainer()->get(ByBitMarketService::class),
            self::getContainer()->get(OrderServiceInterface::class),

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
            ->shouldReceive('getSpotWalletBalance')
            ->with($coin = $symbol->associatedCoin())
            ->andReturn(new WalletBalance(AccountType::SPOT, $coin, $amount, $amount))
        ;
    }

    protected function haveContractWalletBalance(Symbol $symbol, float $total, float $available): void
    {
        $this->exchangeAccountServiceMock
            ->shouldReceive('getContractWalletBalance')
            ->with($coin = $symbol->associatedCoin())
            ->andReturn(new WalletBalance(AccountType::CONTRACT, $coin, $total, $available),)
        ;
    }
}
