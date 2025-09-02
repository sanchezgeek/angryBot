<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Application\Service\Hedge\Hedge;
use App\Domain\Coin\CoinAmount;
use App\Domain\Position\Assertion\PositionSizeAssertion;
use App\Domain\Position\Exception\SizeCannotBeLessOrEqualsZeroException;
use App\Domain\Position\Helper\PositionClone;
use App\Domain\Position\ValueObject\Leverage;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\SymbolPrice;
use App\Helper\FloatHelper;
use App\Trading\Domain\Symbol\SymbolInterface;
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

    public ?Position $oppositePosition = null;
    private Hedge|null|false $hedge = false;

    /**
     * @throws SizeCannotBeLessOrEqualsZeroException
     */
    public function __construct(
        public readonly Side $side,
        public readonly SymbolInterface $symbol,
        public readonly float $entryPrice,
        public readonly float $size,
        public readonly float $value,
        public readonly float $liquidationPrice,
        float $initialMargin,
        int $leverage,
        public readonly ?float $unrealizedPnl = null,
        public readonly bool $isDummyAndFake = false,
    ) {
        PositionSizeAssertion::assert($this->size);

        $this->leverage = new Leverage($leverage);
        $this->initialMargin = new CoinAmount($this->symbol->associatedCoin(), $initialMargin);
    }

    public function liquidationDistance(): float
    {
        return FloatHelper::round(abs($this->entryPrice - $this->liquidationPrice), $this->symbol->pricePrecision());
    }

    public function liquidationPrice(): SymbolPrice
    {
        return $this->symbol->makePrice($this->liquidationPrice);
    }

    public function entryPrice(): SymbolPrice
    {
        return $this->symbol->makePrice($this->entryPrice);
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

    public function isMainPositionOrWithoutHedge(): bool
    {
        $hedge = $this->getHedge();

        return $hedge === null || $hedge->isMainPosition($this);
    }

    public function isPositionWithoutHedge(): bool
    {
        return $this->getHedge() === null;
    }

    public function isShortWithoutLiquidation(): bool
    {
        return $this->isShort() && !$this->liquidationPrice;
    }

    public function getNotCoveredSize(): ?float
    {
        if (!($hedge = $this->getHedge())) {
            return $this->size;
        }

//        if (!$hedge->isMainPosition($this)) {
//            throw new RuntimeException(
//                sprintf('Trying to get `notCoveredSize` of %s, but position is not mainPosition of the hedge.', $this)
//            );
//        }

        return $this->symbol->roundVolume($hedge->mainPosition->size - $hedge->supportPosition->size);
    }

    public function getCaption(): string
    {
        $info = match (true) {
            $this->getHedge()?->isEquivalentHedge() => ' (eqv.hedge)',
            $this->isSupportPosition() => ' (support)',
            $this->isMainPosition() => ' (main)',
            default => ''
        };
        return sprintf('%s %s%s', $this->symbol->name(), $this->side->title(), $info);
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

    public function isPositionInProfit(SymbolPrice $currentPrice): bool
    {
        return $currentPrice->differenceWith($this->entryPrice())->isProfitFor($this->side);
    }

    public function isPositionInLoss(SymbolPrice $currentPrice): bool
    {
        return $currentPrice->differenceWith($this->entryPrice())->isLossFor($this->side);
    }

    public function getVolumePart(float $percent): float
    {
        if ($percent <= 0 || $percent > 100) {
            throw new LogicException(sprintf('Percent value must be in 0..100 range. "%.2f" given.', $percent));
        }

        return $this->symbol->roundVolume($this->size * ($percent / 100));
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
        if (!$this->symbol->eq($ticker->symbol)) {
            throw new LogicException(sprintf('%s: invalid ticker "%s" provided ("%s" expected)', __METHOD__, $ticker->symbol->name(), $this->symbol->name()));
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

    /**
     * @internal Only for tests
     */
    public function uninitializeRuntimeCache(): void
    {
        $this->hedge = false;
    }

    public function isLiquidationPlacedBeforeEntry(): bool
    {
        return $this->isShort()
            ? $this->liquidationPrice()->lessThan($this->entryPrice())
            : $this->liquidationPrice()->greaterThan($this->entryPrice())
        ;
    }
}
