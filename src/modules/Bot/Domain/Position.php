<?php

declare(strict_types=1);

namespace App\Bot\Domain;

use App\Bot\Domain\ValueObject\Symbol;
use App\Domain\Order\Leverage;
use App\Domain\Position\Liquidation\PositionLiquidationTrace\CoinAmount;
use App\Domain\Position\ValueObject\Side;
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

    public function getCaption(): string
    {
        $type = $this->side === Side::Sell ? 'SHORT' : 'LONG';

        return $this->symbol->value . ' ' . $type;
    }

    /**
     * @return float Delta between position->entryPrice and ticker->indexPrice (+ in case of profit / - case of losses)
     */
    public function getDeltaWithTicker(Ticker $ticker): float
    {
        return $this->side === Side::Sell
            ? $this->entryPrice - $ticker->indexPrice
            : $ticker->indexPrice - $this->entryPrice
        ;
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
}
