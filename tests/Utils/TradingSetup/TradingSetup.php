<?php

declare(strict_types=1);

namespace App\Tests\Utils\TradingSetup;

use App\Bot\Domain\Entity\Stop;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\SymbolEnum;
use App\Bot\Domain\ValueObject\SymbolInterface;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Stop\StopsCollection;
use Exception;
use RuntimeException;

final class TradingSetup
{
    private array $tickers = [];
    private array $positions = [];
    private StopsCollection $stopsCollection;

    public function __construct(array $tickers = [], array $positions = [], ?StopsCollection $stopsCollection = null)
    {
        $this->stopsCollection = $stopsCollection ?? new StopsCollection();

        foreach ($tickers as $ticker) {
            $this->addTicker($ticker);
        }

        foreach ($positions as $position) {
            $this->addPosition($position);
        }
    }

    public function getStopsCollection(): StopsCollection
    {
        return $this->stopsCollection;
    }

    public function addStop(Stop $stop): self
    {
        $this->stopsCollection->add($stop);

        return $this;
    }

    public function addTicker(Ticker $ticker): self
    {
        $symbol = $ticker->symbol->value;

        if ($this->tickers[$symbol] ?? null) {
            throw new RuntimeException(sprintf('"%s" ticker already defined', $symbol));
        }

        $this->tickers[$symbol] = $ticker;

        return $this;
    }

    public function addPosition(Position $position): self
    {
        $symbol = $position->symbol->value;
        $side = $position->side->value;

        if ($existed = $this->positions[$symbol][$side] ?? null) {
            throw new RuntimeException(sprintf('"%s" position already defined', $existed->getCaption()));
        }

        $this->positions[$symbol][$side] = $position;

        return $this;
    }

    public function getPosition(SymbolInterface $symbol, Side $side): Position
    {
        if (!$position = $this->positions[$symbol->value][$side->value] ?? null) {
            throw new Exception(sprintf('"%s %s" position not found', $symbol->value, $side->title()));
        }

        return $position;
    }

    public function getStopById(int $id): Stop
    {
        return $this->stopsCollection->getOneById($id);
    }

    /**
     * @return Ticker[]
     */
    public function getTickers(): array
    {
        return $this->tickers;
    }

    /**
     * @return Position[]
     */
    public function getPositions(): array
    {
        $result = [];
        foreach ($this->positions as $symbolRaw => $positions) {
            foreach ($positions as $position) {
                $result[] = $position;
            }
        }

        return $result;
    }
}
