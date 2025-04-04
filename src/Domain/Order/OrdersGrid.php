<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Bot\Domain\Position;
use App\Domain\Position\ValueObject\Side;
use App\Domain\Price\PriceRange;
use DomainException;
use Generator;

use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Order\OrdersGridTest
 */
final class OrdersGrid
{
    private PriceRange $priceRange;
    private Side $positionSide;

    public function __construct(PriceRange $priceRange, ?Side $side = Side::Sell)
    {
        $this->priceRange = $priceRange;
        $this->positionSide = $side;
    }

    /**
     * @todo | Dead code?
     */
    public static function byPositionPnlRange(Position $position, int $fromPnl, int $toPnl): self
    {
        return new self(
            PriceRange::byPositionPnlRange($position, $fromPnl, $toPnl),
            $position->side
        );
    }

    public function getPriceRange(): PriceRange
    {
        return $this->priceRange;
    }

    /**
     * @param float $forVolume
     * @return Generator<Order>
     */
    public function ordersByPriceStep(float $forVolume, int $priceStep): \Generator
    {
        if ($priceStep <= 0) {
            throw new DomainException(sprintf('$priceStep must be greater than zero. "%d" given.', $priceStep));
        }

        if ($priceStep >= $this->getPriceRange()->to()->value() - $this->getPriceRange()->from()->value()) {
            throw new DomainException('PriceRange must be greater than $priceStep.');
        }

        $qnt = $this->getPriceRange()->getItemsQntByStep($priceStep);
        if ($qnt <= 1) {
            throw new DomainException(sprintf('$qnt must be >= 1 (calculated result: %d)', $qnt));
        }

        $volume = $this->getPriceRange()->getSymbol()->roundVolume($forVolume / $qnt);

        foreach ($this->getPriceRange()->byStepIterator($priceStep, $this->positionSide) as $price) {
            yield new Order($price, $volume);
        }
    }

    /**
     * @param float $forVolume
     * @param int $qnt
     * @return Generator<Order>
     */
    public function ordersByQnt(float $forVolume, int $qnt, bool $roundVolumeToMin = false, bool $strict = false): \Generator
    {
        if ($forVolume <= 0) {
            throw new DomainException(sprintf('$forVolume must be greater than zero ("%.2f" given).', $forVolume));
        }

        if ($qnt <= 1) {
            throw new DomainException(sprintf('$qnt must be >= 1 (%d given)', $qnt));
        }

        $symbol = $this->getPriceRange()->getSymbol();

        $volume = $symbol->roundVolume($forVolume / $qnt);
        if ($volume === $symbol->minOrderQty() && $volume * $qnt > $forVolume) {
            $qnt = (int)(max($symbol->minOrderQty(), $forVolume) / $volume);
            $volume = $symbol->roundVolume($forVolume / $qnt);
        }

        $volumeSum = 0;
        /** @var Order[] $orders */
        $orders = [];
        foreach ($this->getPriceRange()->byQntIterator($qnt, $this->positionSide) as $priceItem) {
            if ($strict && $volumeSum >= $forVolume) {
                break;
            }

            if ($roundVolumeToMin) {
                $nominal = ExchangeOrder::roundedToMin($symbol, $volume, $priceItem);
                if ($volume < ($minVolume = $nominal->getVolume())) {
                    $volume = $minVolume;
                }
            }

            if ($strict && $orders) {
                $volumeLeft = $forVolume - $volumeSum;
                if ($volumeLeft < $volume) {
                    $lastOrder = $orders[array_key_last($orders)];

                    $orders[array_key_last($orders)] = new Order($lastOrder->price(), $lastOrder->volume() + $volumeLeft);
                    break;
                }
            }

            $volumeSum += $volume;

            $orders[] = new Order($priceItem, $volume);
        }

        foreach ($orders as $order) {
            yield $order;
        }
    }
}
