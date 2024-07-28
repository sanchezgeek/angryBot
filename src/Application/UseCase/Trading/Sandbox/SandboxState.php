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

class SandboxState
{
    /** @var Position[] */
    private array $positions;
    public readonly Symbol $symbol;
    private Price $lastPrice;

    private CoinAmount $freeBalanceBefore;
    private CoinAmount $availableBalanceBefore;
    private CoinAmount $freeBalance;

    public function __construct(Ticker $ticker, CoinAmount $currentFreeBalance, Position ...$positions)
    {
        $this->setLastPrice($ticker->lastPrice);
        $this->symbol = $ticker->symbol;
        $this->freeBalance = $this->freeBalanceBefore = $currentFreeBalance;

        foreach ($positions as $position) {
            $this->setPosition($position);
        }

        $this->availableBalanceBefore = $this->getAvailableBalance();
    }

    public function getPosition(Side $side): ?Position
    {
        $position = $this->positions[$side->value] ?? null;

        if (!$position) {
            return null;
        }

        if (($opposite = $this->positions[$side->getOpposite()->value] ?? null)) {
            $position->setOppositePosition($opposite, true);
        }

        return $position;
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
        $position = $this->getPosition($position->side);

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
    public function getFreeBalanceBefore(): CoinAmount
    {
        return $this->freeBalanceBefore;
    }

    /**
     * @todo check diff with real
     */
    public function getAvailableBalanceBefore(): CoinAmount
    {
        return $this->availableBalanceBefore;
    }
}