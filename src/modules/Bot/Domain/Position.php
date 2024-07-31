<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Assertion\PositionSizeAssertion;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\Price;
use App\Helper\FloatHelper;
use App\Helper\VolumeHelper;
use Exception;
use LogicException;
use RuntimeException;
use Stringable;

use function assert;
use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Position\PositionTest
 */
final class Position implements Stringable
{
    public readonly Leverage $leverage;
    public readonly CoinAmount $initialMargin;
    public readonly CoinAmount $positionBalance;

    public ?Position $oppositePosition = null;
    private Hedge|null|false $hedge = false;

    /**
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function __construct(
        public readonly Side $side,
        public readonly Symbol $symbol,
        public readonly float $entryPrice,
        public readonly float $size,
        public readonly float $value,
        public readonly float $liquidationPrice,
        float $initialMargin,
        float $positionBalance,
        int $leverage,
        public readonly ?float $unrealizedPnl = null,
    ) {
        PositionSizeAssertion::assert($this->size);

        $this->leverage = new Leverage($leverage);
        $this->initialMargin = new CoinAmount($this->symbol->associatedCoin(), $initialMargin);
        $this->positionBalance = new CoinAmount($this->symbol->associatedCoin(), $positionBalance);
    }

    public function liquidationDistance(): float
    {
        return abs(FloatHelper::round($this->entryPrice - $this->liquidationPrice));
    }

    public function liquidationPrice(): Price
    {
        return Price::toObj($this->liquidationPrice);
    }

    public function setOppositePosition(Position $oppositePosition): void
    {
        assert($this->oppositePosition === null, new LogicException('Opposite position already set.'));
        assert($this->hedge === false, new LogicException('Hedge already initialized => `oppositePosition` cannot be changed.'));

        if ($this->side === $oppositePosition->side) {
            throw new LogicException('Provided position is on the same side.');
        }

        $this->oppositePosition = $oppositePosition;
    }

    public function getHedge(): ?Hedge
    {
        if ($this->hedge !== false) {
            return $this->hedge;
        }

        $this->initializeHedge();

        return $this->hedge;
    }

    /**
     * @internal || in tests (e.g. for correct check when check positions equality)
     */
    public function initializeHedge(): void
    {
        $this->hedge = $this->oppositePosition !== null ? Hedge::create($this, $this->oppositePosition) : null;
    }

    public function isSupportPosition(): bool
    {
        return ($hedge = $this->getHedge()) && $hedge->isSupportPosition($this);
    }

    public function isMainPosition(): bool
    {
        return ($hedge = $this->getHedge()) && $hedge->isMainPosition($this);
    }

    public function getNotCoveredSize(): ?float
    {
        if (!($hedge = $this->getHedge())) {
            return $this->size;
        }

        if (!$hedge->isMainPosition($this)) {
            throw new RuntimeException(
                sprintf('Trying to get `notCoveredSize` of %s, but position is not mainPosition of the hedge.', $this)
            );
        }

        return VolumeHelper::round($hedge->mainPosition->size - $hedge->supportPosition->size);
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
        return Price::toObj($currentPrice)->differenceWith($this->entryPrice)->isProfitFor($this->side);
    }

    public function isPositionInLoss(Price|float $currentPrice): bool
    {
        return Price::toObj($currentPrice)->differenceWith($this->entryPrice)->isLossFor($this->side);
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

    public function priceDistanceWithLiquidation(Ticker $ticker): float
    {
        if ($this->symbol !== $ticker->symbol) {
            throw new LogicException(sprintf('%s: invalid ticker "%s" provided ("%s" expected)', __METHOD__, $ticker->symbol->name, $this->symbol->name));
        }

        return $this->liquidationPrice()->deltaWith($ticker->markPrice);
    }

    public function __toString(): string
    {
        return $this->getCaption();
    }

    /**
     * @throws Exception
     */
    public function __clone(): void
    {
        throw new Exception(sprintf('%s: clone denied. Use %s instead.', __METHOD__, PositionClone::class));
    }
}
