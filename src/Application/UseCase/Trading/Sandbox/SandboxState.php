<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxHedgeIsEquivalentException;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use InvalidArgumentException;

use function array_values;
use function assert;
use function count;
use function max;
use function sprintf;

class SandboxState
{
    /** @var Position[] */
    private array $positions;
    public readonly Symbol $symbol;
    private Price $lastPrice;

    private CoinAmount $freeBalance;

    public function __construct(Ticker $ticker, CoinAmount $currentFreeBalance, Position ...$positions)
    {
        $this->setLastPrice($ticker->lastPrice);
        $this->symbol = $ticker->symbol;
        $this->freeBalance = $currentFreeBalance;

        foreach ($positions as $position) {
            $this->setPosition($position);
        }
    }

    /**
     * @return Position[]
     */
    public function getPositions(): array
    {
        return $this->positions;
    }

    public function getPosition(Side $side): ?Position
    {
        return $this->positions[$side->value] ?? null;
    }

    /**
     * For check liquidation, for example
     *
     * @throws SandboxHedgeIsEquivalentException
     */
    public function getMainPosition(): ?Position
    {
        if (!count($this->positions)) {
            return null;
        }

        $position = $this->getPosition(array_values($this->positions)[0]->side);
        $hedge = $position->getHedge();

        if ($hedge?->isEquivalentHedge()) {
            throw new SandboxHedgeIsEquivalentException();
        }

        return $hedge?->mainPosition ?? $position;
    }

    public function setPosition(Position|ClosedPosition $input): self
    {
        assert($this->symbol === $input->symbol, new InvalidArgumentException(
            sprintf('%s: incorrect usage (positions with "%s" symbol expected, but %s provided)', __METHOD__, $this->symbol->value, $input->symbol->value)
        ));
        $positionSide = $input->side;
        $oppositeSide = $positionSide->getOpposite();

        $position = $input instanceof ClosedPosition ? null : PositionClone::clean($input)->create();

        # if position on other side exists, do cross-set
        if ($opposite = $this->getPosition($oppositeSide)) {
            $oppositeClone = PositionClone::clean($opposite)->withOpposite($position)->create();
            $position && $position->setOppositePosition($oppositeClone);
            $this->positions[$oppositeSide->value] = $oppositeClone;
        }

        // @todo | set unrealizedPnl?

        if ($position) {
            $this->positions[$positionSide->value] = $position;
        } else {
            unset($this->positions[$positionSide->value]);
        }

        return $this;
    }

    public function modifyFreeBalance(CoinAmount|float $amount): self
    {
        $this->freeBalance = $this->freeBalance->add($amount);
        return $this;
    }

    public function getFreeBalance(): CoinAmount
    {
        return $this->freeBalance;
    }

    public function getAvailableBalance(): CoinAmount
    {
        $lastPrice = $this->lastPrice;

        try {
            $positionForCalcLoss = $this->getMainPosition();
        } catch (SandboxHedgeIsEquivalentException $e) {
            return $this->freeBalance;
        }

        if ($positionForCalcLoss->isPositionInLoss($lastPrice)) {
            $priceDelta = $lastPrice->differenceWith($positionForCalcLoss->entryPrice);
            $loss = $positionForCalcLoss->getNotCoveredSize() * $priceDelta->absDelta();

            $available = $this->freeBalance->sub($loss)->value();
        } else {
            $available = $this->freeBalance->value();
        }

        $available = max($available, 0);

        return new CoinAmount($this->freeBalance->coin(), $available);
    }

    public function setLastPrice(Price|float $price): self
    {
        $this->lastPrice = Price::toObj($price);
        return $this;
    }
}