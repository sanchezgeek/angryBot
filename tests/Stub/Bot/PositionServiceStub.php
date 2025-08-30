<?php

declare(strict_types=1);

namespace App\Tests\Stub\Bot;

use App\Bot\Application\Service\Exchange\PositionServiceInterface;
use App\Bot\Domain\Position;
use App\Domain\Order\Parameter\TriggerBy;
use App\Domain\Position\ValueObject\Side;
use App\Trading\Domain\Symbol\SymbolInterface;
use Exception;
use LogicException;

use RuntimeException;

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
    private array $pushedStopsExchangeOrderIds = [];

    public function getPosition(SymbolInterface $symbol, Side $side): ?Position
    {
        foreach ($this->positions as $position) {
            if ($position->symbol->eq($symbol) && $position->side === $side) {
                return $position;
            }
        }

        return null;
    }

    public function getPositions(SymbolInterface $symbol): array
    {
        return $this->positions;
    }

    public function addConditionalStop(Position $position, float $price, float $qty, TriggerBy $triggerBy): string
    {
        $this->addStopMethodCalls[] = [$position, $price, $qty, $triggerBy];

        $exchangeOrderId = $this->getNextExchangeOrderId();
        $this->pushedStopsExchangeOrderIds[] = $exchangeOrderId;

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

    public function setMockedExchangeOrdersIds(array $mockedExchangeOrdersIds): self
    {
        $this->mockedExchangeOrdersIds = $mockedExchangeOrdersIds;

        return $this;
    }

    public function havePosition(Position $position, bool $replace = false): self
    {
        foreach ($this->positions as $key => $item) {
            if ($item->symbol->eq($position->symbol) && $item->side === $position->side) {
                if (!$replace) {
                    throw new LogicException(
                        sprintf('Position %s:%s already in array', $item->symbol->name(), $item->side->title())
                    );
                }
                unset($this->positions[$key]);
            }
        }

        $this->positions[] = $position;

        return $this;
    }

    public function getOpenedPositionsSymbols(SymbolInterface ...$except): array
    {
        throw new Exception(sprintf('%s::getOpenedPositionsSymbols not supported', PositionServiceInterface::class));
    }

    public function setLeverage(SymbolInterface $symbol, float $forBuy, float $forSell): void
    {
        throw new RuntimeException('not implemented');
    }
}
