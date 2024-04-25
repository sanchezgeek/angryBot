<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Domain\Value\Percent\Percent;
use App\Helper\VolumeHelper;
use LogicException;

use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Position\PositionTest
 */
final class Position
{
    public readonly CoinAmount $initialMargin;
    public readonly Leverage $leverage;
    public ?Position $oppositePosition = null;

    public function __construct(
        public readonly Side $side,
        public readonly Symbol $symbol,
        public readonly float $entryPrice,
        public readonly float $size,
        public readonly float $value,
        public readonly float $liquidationPrice,
        float $initialMargin,
        int $leverage,
        public readonly ?float $unrealizedPnl = null,
    ) {
        $this->initialMargin = new CoinAmount($this->symbol->associatedCoin(), $initialMargin);
        $this->leverage = new Leverage($leverage);
    }

    public function cloneWithNewSize(float $newSize): self
    {
        $entryPrice = $this->entryPrice;
        $newValue = $entryPrice * $newSize; // linear

        $position = new Position(
            $this->side,
            $this->symbol,
            $entryPrice,
            $newSize,
            $newValue,
            $this->liquidationPrice,
            $newValue / $this->leverage->value(),
            $this->leverage->value()
        );

        if ($this->oppositePosition) {
            $position->setOppositePosition($this->oppositePosition);
        }

        return $position;
    }

    /**
     * @todo Get from response
     */
    public function getPositionBalance(): CoinAmount
    {
        return $this->initialMargin->addPercent(Percent::string('5%'));
    }

    public function setOppositePosition(Position $oppositePosition): void
    {
        if ($this->oppositePosition !== null) {
            throw new LogicException('Opposite position already set.');
        }

        if ($this->side === $oppositePosition->side) {
            throw new LogicException('Provided position is on the same side.');
        }

        $this->oppositePosition = $oppositePosition;
    }

    public function getHedge(): ?Hedge
    {
        return $this->oppositePosition === null ? null : Hedge::create($this, $this->oppositePosition);
    }

    public function isSupportPosition(): bool
    {
        return ($hedge = $this->getHedge()) && $hedge->isSupportPosition($this);
    }

    public function isMainPosition(): bool
    {
        return ($hedge = $this->getHedge()) && $hedge->isMainPosition($this);
    }

    public function getCaption(): string
    {
        return $this->symbol->value . ' ' . $this->side->title();
    }

    /**
     * @return float Delta between position->entryPrice and ticker->indexPrice (+ in case of profit / - case of losses)
     */
    public function getDeltaWithTicker(Ticker $ticker): float
    {
        return $this->side === Side::Sell
            ? $this->entryPrice - $ticker->indexPrice->value()
            : $ticker->indexPrice->value() - $this->entryPrice
        ;
    }

    public function isPositionInProfit(Price|float $currentPrice): bool
    {
        $currentPrice = $currentPrice instanceof Price ? $currentPrice->value() : $currentPrice;

        return $this->isShort() ? $this->entryPrice > $currentPrice : $this->entryPrice < $currentPrice
        ;
    }

    public function isPositionInLoss(Price|float $currentPrice): bool
    {
        $currentPrice = $currentPrice instanceof Price ? $currentPrice->value() : $currentPrice;

        return $this->isShort() ? $this->entryPrice < $currentPrice : $this->entryPrice > $currentPrice;
    }

    public function getVolumePart(float $percent): float
    {
        if ($percent <= 0 || $percent > 100) {
            throw new LogicException(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $percent));
        }

        return VolumeHelper::round($this->size * ($percent / 100));
    }

    public function isShort(): bool
    {
        return $this->side->isShort();
    }

    public function isLong(): bool
    {
        return $this->side->isLong();
    }

    public function priceDeltaToLiquidation(Ticker $ticker): float
    {
        if ($this->symbol !== $ticker->symbol) {
            throw new LogicException(sprintf('%s: invalid ticker "%s" provided ("%s" expected)', __METHOD__, $ticker->symbol->name, $this->symbol->name));
        }

        return abs($this->liquidationPrice - $ticker->markPrice->value());
    }
}
