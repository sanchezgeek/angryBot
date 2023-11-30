<?php

declare(strict_types=1);

namespace App\Tests\Stub\Bot;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use LogicException;

use function array_shift;
use function sprintf;
use function uuid_create;

/**
 * @see \App\Tests\Unit\Stub\PositionServiceStubTest
 */
final class PositionServiceStub implements PositionServiceInterface
{
    /**
     * @var Position[]
     */
    private array $positions = [];

    private array $mockedExchangeOrdersIds = [];
    private array $addStopMethodCalls = [];
    private array $addBuyOrderMethodCalls = [];
    private array $pushedStopsExchangeOrderIds = [];
    private array $pushedBuyOrdersExchangeOrderIds = [];

    public function getPosition(Symbol $symbol, Side $side): ?Position
    {
        foreach ($this->positions as $position) {
            if ($position->symbol === $symbol && $position->side === $side) {
                return $position;
            }
        }

        return null;
    }

    public function getOppositePosition(Position $position): ?Position
    {
        foreach ($this->positions as $item) {
            if ($item->symbol === $position->symbol && $item->side === $position->side->getOpposite()) {
                return $position;
            }
        }

        return null;
    }

    public function addConditionalStop(Position $position, Ticker $ticker, float $price, float $qty, TriggerBy $triggerBy): string
    {
        $this->addStopMethodCalls[] = [$position, $ticker, $price, $qty, $triggerBy];

        $exchangeOrderId = $this->getNextExchangeOrderId();
        $this->pushedStopsExchangeOrderIds[] = $exchangeOrderId;

        return $exchangeOrderId;
    }

    public function marketBuy(Position $position, Ticker $ticker, float $price, float $qty): string
    {
        $this->addBuyOrderMethodCalls[] = [$position, $ticker, $price, $qty];

        $exchangeOrderId = $this->getNextExchangeOrderId();
        $this->pushedBuyOrdersExchangeOrderIds[] = $exchangeOrderId;

        return $exchangeOrderId;
    }

    private function getNextExchangeOrderId(): string
    {
        if (!($exchangeOrderId = array_shift($this->mockedExchangeOrdersIds))) {
            $exchangeOrderId = uuid_create();
        }

        return $exchangeOrderId;
    }

    public function getPushedStopsExchangeOrderIds(): array
    {
        return $this->pushedStopsExchangeOrderIds;
    }

    public function getAddStopCallsStack(): array
    {
        return $this->addStopMethodCalls;
    }

    public function getAddBuyOrderCallsStack(): array
    {
        return $this->addBuyOrderMethodCalls;
    }

    public function setMockedExchangeOrdersIds(array $mockedExchangeOrdersIds): self
    {
        $this->mockedExchangeOrdersIds = $mockedExchangeOrdersIds;

        return $this;
    }

    public function havePosition(Position $position, bool $replace = false): self
    {
        foreach ($this->positions as $key => $item) {
            if ($item->symbol === $position->symbol && $item->side === $position->side) {
                if (!$replace) {
                    throw new LogicException(
                        sprintf('Position %s:%s already in array', $item->symbol->value, $item->side->title())
                    );
                }
                unset($this->positions[$key]);
            }
        }

        $this->positions[] = $position;

        return $this;
    }
}
