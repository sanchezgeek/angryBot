<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Bot\Domain\Position;
use App\Domain\Price\PriceRange;
use App\Helper\VolumeHelper;
use DomainException;
use Generator;

use function sprintf;

/**
 * @see \App\Tests\Unit\Domain\Order\OrdersGridTest
 */
final class OrdersGrid
{
    private PriceRange $priceRange;

    public function __construct(PriceRange $priceRange)
    {
        $this->priceRange = $priceRange;
    }

    /**
     * @todo | Dead code?
     */
    public static function byPositionPnlRange(Position $position, int $fromPnl, int $toPnl): self
    {
        return new self(PriceRange::byPositionPnlRange($position, $fromPnl, $toPnl));
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

        $volume = VolumeHelper::round($forVolume / $qnt);

        foreach ($this->getPriceRange()->byStepIterator($priceStep) as $price) {
            yield new Order($price, $volume);
        }
    }

    /**
     * @param float $forVolume
     * @param int $qnt
     * @return Generator<Order>
     */
    public function ordersByQnt(float $forVolume, int $qnt): \Generator
    {
        if ($forVolume <= 0) {
            throw new DomainException(sprintf('$forVolume must be greater than zero ("%.2f" given)', $forVolume));
        }

        if ($qnt <= 1) {
            throw new DomainException(sprintf('$qnt must be >= 1 (%d given)', $qnt));
        }

        $volume = VolumeHelper::round($forVolume / $qnt);

        foreach ($this->getPriceRange()->byQntIterator($qnt) as $priceItem) {
            yield new Order($priceItem, $volume);
        }
    }
}
