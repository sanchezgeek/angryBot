<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Entity\BuyOrder;
use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Clock\ClockInterface;
use App\Tests\Mixin\TestWithDbFixtures;
use App\Tests\Stub\Bot\PositionServiceStub;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

use function implode;
use function sprintf;

abstract class PushOrderHandlerTestAbstract extends KernelTestCase
{
    use TestWithDbFixtures;

    protected MessageBusInterface $messageBus;
    protected EventDispatcherInterface $eventDispatcher;
    protected HedgeService $hedgeService;
    protected StopService $stopService;
    protected StopRepository $stopRepository;

    protected PositionServiceInterface $positionServiceStub;
    protected ExchangeServiceInterface $exchangeServiceMock;
    protected LoggerInterface $loggerMock;
    protected ClockInterface $clockMock;

    protected function setUp(): void
    {
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $this->hedgeService = self::getContainer()->get(HedgeService::class);
        $this->stopService = self::getContainer()->get(StopService::class);
        $this->stopRepository = self::getContainer()->get(StopRepository::class);

        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceStub = new PositionServiceStub();
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);
    }

    protected function haveTicker(Ticker $ticker): void
    {
        $this->exchangeServiceMock->method('ticker')->with($ticker->symbol)->willReturn($ticker);
    }

    /**
     * @param array<Stop|BuyOrder> $orders
     */
    protected function ordersDesc(...$orders): string
    {
        $descriptions = [];
        foreach ($orders as $order) {
            $descriptions[] = sprintf('%.1f (%.3f)', $order->getPrice(), $order->getVolume());
        }
        return implode(', ', $descriptions);
    }
}
