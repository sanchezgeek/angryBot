<?php

declare(strict_types=1);

namespace App\Application\UseCase\Trading\Sandbox;

use App\Application\UseCase\Trading\Sandbox\Dto\ClosedPosition;
use App\Application\UseCase\Trading\Sandbox\Exception\SandboxHedgeIsEquivalentException;
use App\Bot\Application\Service\Exchange\Dto\ContractBalance;
use App\Bot\Domain\Position;
use App\Bot\Domain\Ticker;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Trading\Domain\Symbol\SymbolInterface;
use InvalidArgumentException;

use function array_values;
use function assert;
use function count;
use function max;
use function sprintf;

class SandboxState implements SandboxStateInterface
{
    /** @var Position[] */
    private array $positions = [];
    public readonly SymbolInterface $symbol;
    private SymbolPrice $lastPrice;

    public ContractBalance $contractBalance;
    private CoinAmount $freeBalance;
    private CoinAmount $fundsAvailableForLiquidation;

    public function __construct(
        Ticker $ticker,
        ContractBalance $contractBalance,
        CoinAmount $fundsAvailableForLiquidation,
        Position ...$positions
    ) {
        $this->contractBalance = $contractBalance;

        $this->symbol = $ticker->symbol;
        $this->setLastPrice($ticker->lastPrice);
        $this->freeBalance = $contractBalance->free;
        $this->fundsAvailableForLiquidation = $fundsAvailableForLiquidation;

        foreach ($positions as $position) {
            $this->setPositionAndActualizeOpposite($position);
        }
    }

    public function getSymbol(): SymbolInterface
    {
        return $this->symbol;
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

    /**
     * @todo | Move back to TradingSandbox
     *   reason: this method also must actualize liquidation with: 1) free balance, 2) opposite
     *           @see \App\Tests\Unit\Application\UseCase\Trading\Sandbox\SandboxStateTest::testSetClosedPosition
     */
    public function setPositionAndActualizeOpposite(Position|ClosedPosition $input): void
    {
        assert($this->symbol->eq($input->symbol), new InvalidArgumentException(
            sprintf('%s: incorrect usage (positions with "%s" symbol expected, but %s provided)', __METHOD__, $this->symbol->name(), $input->symbol->name())
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
    }

    public function addFreeBalance(CoinAmount|float $amount): self
    {
        $this->freeBalance = $this->freeBalance->add($amount);
        // @todo | sandbox | research impact on balance
        $this->fundsAvailableForLiquidation = $this->fundsAvailableForLiquidation->add($amount);
        return $this;
    }

    public function subFreeBalance(CoinAmount|float $amount): self
    {
        $this->freeBalance = $this->freeBalance->sub($amount);
        // @todo | sandbox | research impact on balance
        $this->fundsAvailableForLiquidation = $this->fundsAvailableForLiquidation->sub($amount);
        return $this;
    }

    public function getContractBalance(): ContractBalance
    {
        return $this->contractBalance;
    }

    public function setContractBalance(ContractBalance $contractBalance): self
    {
        $this->contractBalance = $contractBalance;

        return $this;
    }

    public function getFreeBalance(): CoinAmount
    {
        return $this->freeBalance;
    }

    public function getFundsAvailableForLiquidation(): CoinAmount
    {
        return $this->fundsAvailableForLiquidation;
    }

    /**
     * @todo | sandbox | get rid of SandboxState::getAvailableBalance or replace logic based on SandboxState::contractBalance
     */
    public function getAvailableBalance(): CoinAmount
    {
        // @todo | sandbox | add unrealizedPNL (isUTA)
        $lastPrice = $this->lastPrice;

        try {
            $positionForCalcLoss = $this->getMainPosition();
        } catch (SandboxHedgeIsEquivalentException $e) {
            return $this->freeBalance;
        }

        // + test: all free must be available
        if ($positionForCalcLoss?->isPositionInLoss($lastPrice)) {
            $priceDelta = $lastPrice->differenceWith($positionForCalcLoss->entryPrice());
            $loss = $positionForCalcLoss->getNotCoveredSize() * $priceDelta->absDelta();

            $available = $this->freeBalance->sub($loss)->value();
        } else {
            $available = $this->freeBalance->value();
        }

        $available = max($available, 0);

        return new CoinAmount($this->freeBalance->coin(), $available);
    }

    public function setLastPrice(SymbolPrice|float $price): self
    {
        $this->lastPrice = $this->symbol->makePrice(SymbolPrice::toFloat($price));

        return $this;
    }
}
