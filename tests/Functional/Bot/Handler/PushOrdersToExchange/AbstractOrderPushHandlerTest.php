<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bot\Handler\PushOrdersToExchange;

use App\Bot\Application\Service\Exchange\ExchangeServiceInterface;
use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Application\Service\Hedge\HedgeService;
use App\Bot\Application\Service\Orders\StopService;
use App\Bot\Domain\Position;
use App\Bot\Domain\Repository\StopRepository;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Clock\ClockInterface;
use App\Domain\Position\ValueObject\Side;
use App\Tests\Mixin\DbFixtureTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

abstract class AbstractOrderPushHandlerTest extends KernelTestCase
{
    use DbFixtureTrait;

    protected MessageBusInterface $messageBus;
    protected EventDispatcherInterface $eventDispatcher;
    protected HedgeService $hedgeService;
    protected StopService $stopService;
    protected StopRepository $stopRepository;

    protected PositionServiceInterface $positionServiceMock;
    protected ExchangeServiceInterface $exchangeServiceMock;
    protected LoggerInterface $loggerMock;
    protected ClockInterface $clockMock;

    protected ?Position $position;
    protected ?Ticker $ticker;

    protected array $positionServiceCalls;

    protected function setUp(): void
    {
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $this->hedgeService = self::getContainer()->get(HedgeService::class);
        $this->stopService = self::getContainer()->get(StopService::class);
        $this->stopRepository = self::getContainer()->get(StopRepository::class);

        $this->exchangeServiceMock = $this->createMock(ExchangeServiceInterface::class);
        $this->positionServiceMock = $this->createMock(PositionServiceInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->clockMock = $this->createMock(ClockInterface::class);

        $this->position = null;
        $this->ticker = null;
        $this->positionServiceCalls = [];

        $this->beginFixturesTransaction();
    }

    protected function havePosition(Symbol $symbol, Side $side, float $at, int $size = 1): Position
    {
        $this->position = new Position($side, $symbol, $at, 1, $value = $at * $size, $at + 1000, $value / 100, 100);

        $this->positionServiceMock->method('getPosition')->with($symbol)->willReturn($this->position);

        return $this->position;
    }

    protected function haveTicker(Symbol $symbol, float $at): Ticker
    {
        $this->ticker = new Ticker($symbol, $at - 10, $at, 'test');

        $this->exchangeServiceMock->method('ticker')->with($symbol)->willReturn($this->ticker);

        return $this->ticker;
    }
}
