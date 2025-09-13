<?php

declare(strict_types=1);

namespace App\Trading\Application\UseCase\TruncateOrders;

use InvalidArgumentException;
use Stringable;

final readonly class TruncateOrdersResult implements Stringable
{
    public function __construct(
        public ?int $totalStopsCount = null,
        public ?int $removedStopsCount = null,
        public ?int $totalBuyOrdersCount = null,
        public ?int $removedBuyOrdersCount = null,
        public array $errors = [],
    ) {
        if ($this->totalStopsCount !== null && $this->removedStopsCount === null) {
            throw new InvalidArgumentException(sprintf('`removedStopsCount` cannot be null when `totalStopsCount` is not null'));
        }

        if ($this->totalBuyOrdersCount !== null && $this->removedBuyOrdersCount === null) {
            throw new InvalidArgumentException(sprintf('`removedBuyOrdersCount` cannot be null when `totalBuyOrdersCount` is not null'));
        }
    }

    public function __toString(): string
    {
        $info = [];

        if ($this->totalStopsCount !== null) {
            $info[] = sprintf('Removed Stops: %d / total Stops: %s', $this->removedStopsCount, $this->totalStopsCount);
        }

        if ($this->totalBuyOrdersCount !== null) {
            $info[] = sprintf('Removed BuyOrders: %d / total BuyOrders: %s', $this->removedBuyOrdersCount, $this->totalBuyOrdersCount);
        }

        if ($this->errors) {
            $info[] = sprintf("Errors:\n%s", implode("\n", $this->errors));
        }

        return $info ? implode(' | ', $info) : '';
    }
}
