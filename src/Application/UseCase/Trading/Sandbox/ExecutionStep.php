<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use InvalidArgumentException;

use function array_values;
use function assert;
use function count;
use function max;
use function reset;
use function sprintf;

class ExecutionStep
{
    /** @var Position[] */
    private array $positions;
    private Symbol $symbol;
    private Price $lastPrice;

    private CoinAmount $freeBalanceBeforeStep;
    private CoinAmount $availableBalanceBeforeStep;
    private CoinAmount $freeBalance;

    public function __construct(Ticker $ticker, CoinAmount $freeBalanceBeforeStep, Position ...$positions)
    {
        $this->setLastPrice($ticker->lastPrice);
        $this->symbol = $ticker->symbol;
        $this->freeBalance = $this->freeBalanceBeforeStep = $freeBalanceBeforeStep;

        foreach ($positions as $position) {
            $this->setPosition($position);
        }

        $this->availableBalanceBeforeStep = $this->getAvailableBalance();
    }

    public function getPosition(Side $side): ?Position
    {
        return $this->positions[$side->value] ?? null;
    }

    /**
     * For check liquidation, for example
     */
    public function getMainPosition(): ?Position
    {
        if (!count($this->positions)) {
            return null;
        }

        $position = array_values($this->positions)[0];

        return $position->getHedge()?->mainPosition ?? $position;
    }

    public function setPosition(Position $position): self
    {
        assert($this->symbol === $position->symbol, new InvalidArgumentException(
            sprintf('%s: incorrect usage (positions with "%s" symbol expected, but %s provided)', __METHOD__, $this->symbol->value, $position->symbol->value)
        ));

        $this->positions[$position->side->value] = $position;

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

        if (count($this->positions) > 1) {
            $hedge = Hedge::create(...array_values($this->positions));
            if ($hedge->isEquivalentHedge()) {
                return $this->freeBalance;
            }

            $positionForCalcLoss = $hedge->mainPosition;
        } else {
            $positionForCalcLoss = reset($this->positions);
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

    /**
     * @todo check diff with real
     */
    public function getFreeBalanceBeforeStep(): CoinAmount
    {
        return $this->freeBalanceBeforeStep;
    }

    /**
     * @todo check diff with real
     */
    public function getAvailableBalanceBeforeStep(): CoinAmount
    {
        return $this->availableBalanceBeforeStep;
    }
}