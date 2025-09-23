<?php

declare(strict_types=1);

namespace App\Trading\Application\AutoOpen\Decision\Criteria\ByReason\SignificantPriceChangeFound;

use App\Domain\Position\ValueObject\Side;
use App\Domain\Value\Percent\Percent;
use App\Trading\Application\AutoOpen\Decision\Criteria\AbstractOpenPositionCriteria;

final class AthPricePartCriteria extends AbstractOpenPositionCriteria
{
    public static function getAlias(): string
    {
        return 'price-percent-of-ath';
    }

    /**
     * @param array<string, Percent> $athPartThresholdModifiers
     */
    public function __construct(
        private array $athPartThresholdModifiers = [],
    ) {
        foreach ($this->athPartThresholdModifiers as $key => $modifier) {
            Side::from($key);
            assert($modifier instanceof Percent);
        }
    }

    public function addThresholdModifier(Side $side, Percent $modifier): self
    {
        $this->athPartThresholdModifiers[$side->value] = $modifier;

        return $this;
    }

    public function getAthThresholdModifier(Side $side): ?Percent
    {
        return $this->athPartThresholdModifiers[$side->value] ?? null;
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'athPartThresholdModifiers' => $this->athPartThresholdModifiers
        ]);
    }
}
